<?php
/**
 * Copyright (C)  2018 youri.
 * Permission is granted to copy, distribute and/or modify this document
 * under the terms of the GNU Free Documentation License, Version 1.3
 * or any later version published by the Free Software Foundation;
 * with no Invariant Sections, no Front-Cover Texts, and no Back-Cover Texts.
 * A copy of the license is included in the section entitled "GNU
 * Free Documentation License".
 *
 * @date: 9/11/18 / 11:39 AM
 * @author: Youri van den Bogert
 * @url: http://www.xl-knowledge.nl/
 */

namespace MediaWiki\Extensions\GoogleAuthenticator;

use MediaWiki\Auth\AbstractSecondaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Logger\LoggerFactory;
use MWCryptRand;

class Google2FactorSecondaryAuthenticationProvider extends AbstractSecondaryAuthenticationProvider {

	/** @var int Maximum allowed of retries */
	const MAX_RETRIES = 4;

	/** @var string */
	const OPT_SECRET = 'Google2FA_Secret';

	/** @var string */
	const OPT_SECRET_SETUP = 'Google2FA_Secret_SetupComplete';

	/** @var string */
	const OPT_RESCUE_1 = 'Google2FA_SecretRescue1';

	/** @var string */
	const OPT_RESCUE_2 = 'Google2FA_SecretRescue2';

	/** @var string */
	const OPT_RESCUE_3 = 'Google2FA_SecretRescue3';

	/**
	 * @param string $action
	 * @param array $options
	 * @return array|\MediaWiki\Auth\AuthenticationRequest[]
	 */
	public function getAuthenticationRequests( $action, array $options ) {
		return [];
	}

	public function beginSecondaryAuthentication( $user, array $reqs ) {

		$secret = $user->getOption( self::OPT_SECRET, false );
		$secretSetup = $user->getOption( self::OPT_SECRET_SETUP, false);
		$generateNew = ( !$secretSetup );
		$rescueCodes = [];

		if ($generateNew) {

			// Generate a new secret
			$secret = $this->generateSecrets( $user );

			// Set rescue codes
			$rescueCodes = [
				$user->getOption(self::OPT_RESCUE_1),
				$user->getOption(self::OPT_RESCUE_2),
				$user->getOption(self::OPT_RESCUE_3)
			];

			// Log action
			LoggerFactory::getInstance('Google2FA')->info(
				'Generated new token for {user}',
				[ 'user' => $user->getName() ]
			);

		}

		return AuthenticationResponse::newUI(
			[ new Google2FactorAuthenticationRequest( $secret, $generateNew, $rescueCodes ) ],
			wfMessage('google2fa-info')
		);

	}

	public function continueSecondaryAuthentication( $user, array $reqs ) {

		// Fetch the secret
		$secret = $user->getOption(self::OPT_SECRET, false);

		// Fetch rescue options
		$rescueCodes = [
			$user->getOption(self::OPT_RESCUE_1),
			$user->getOption(self::OPT_RESCUE_3),
			$user->getOption(self::OPT_RESCUE_2),
		];

		/** @var Google2FactorAuthenticationRequest $req */
		$req = AuthenticationRequest::getRequestByClass( $reqs, Google2FactorAuthenticationRequest::class );


		// If the user has given a rescue code, reset the OPT_SECRET_SETUP and show the form again
		if( $req && in_array( $req->token, $rescueCodes ) ) {

			// Reset all the codes of the user
			$this->resetSecretCodes( $user );

			LoggerFactory::getInstance( 'Google2FA' )
				->info( 'Succesfully reset secret for {user}', [ 'user' => $user->getName() ] );

			// Return the 2 FA authentication process again
			return $this->beginSecondaryAuthentication( $user, $reqs );

		// Wrong token given upon new code
		} else if ( $req && !$this->getGoogleAuthClass()->verifyCode($secret, $req->token) && !$user->getOption(self::OPT_SECRET_SETUP,false)  ) {

			// Reset all the codes of the user
			$this->resetSecretCodes( $user );

			// Return the 2 FA authentication process again
			return $this->beginSecondaryAuthentication( $user, $reqs );

		// We have a valid session when the code has been verified succesfully
		} else if ( $req && $this->getGoogleAuthClass()->verifyCode( $secret, $req->token ) ) {

			// The secret has been saved in to the DB and the given code was
			// validated. Save it to the DB
			if( $user->getOption(self::OPT_SECRET_SETUP, false ) === false ) {

				// Set option & save settings
				$user->setOption( self::OPT_SECRET_SETUP, "1" );
				$user->saveSettings();

				LoggerFactory::getInstance('Google2FA')->info(
					'Succesfully validated new secret for {user}',
					[ 'user' => $user->getName() ]
				);

			}

			return AuthenticationResponse::newPass();

		// Invalid code given
		} else if ( $req && !$this->getGoogleAuthClass()->verifyCode($secret, $req->token)) {
			LoggerFactory::getInstance('Google2FA')->info( 'Invalid token for {user}', [ 'user' => $user->getName() ] );
		}

		// Fetch the num of failures
		$failures = $this->manager->getAuthenticationSessionData( 'AuthFailures' );
		if ( $failures >= self::MAX_RETRIES ) {
			return AuthenticationResponse::newFail( wfMessage( 'google2fa-login-retry-limit' ) );
		}

		// Save num of failures
		$this->manager->setAuthenticationSessionData( 'AuthFailures', $failures + 1 );

		// Return the authentication request
		return AuthenticationResponse::newUI(
			[ new Google2FactorAuthenticationRequest($secret) ],
			wfMessage( 'google2fa-login-failure' ),
			'error'
		);

	}

	public function beginSecondaryAccountCreation( $user, $creator, array $reqs ) {
		return AuthenticationResponse::newAbstain();
	}

	/**
	 * Generates the secrets for the given user and returns the main secret
	 *
	 * @param \User $user
	 * @return string
	 * @throws \Exception
	 */
	private function generateSecrets( $user ) {

		$secrets = [
			$this->getGoogleAuthClass()->createSecret(),
			MWCryptRand::generateHex( 16 ),
			MWCryptRand::generateHex( 16 ),
			MWCryptRand::generateHex( 16 )
		];

		// Save secrets
		$user->setOption( self::OPT_SECRET, $secrets[0] );
		$user->setOption( self::OPT_RESCUE_1, $secrets[1] );
		$user->setOption( self::OPT_RESCUE_2, $secrets[2] );
		$user->setOption( self::OPT_RESCUE_3, $secrets[3] );

		// Save user settings
		$user->saveSettings();

		// Return the first secret
		return $secrets[0];

	}

	/**
	 * Resets all the codes for the given user
	 *
	 * @param $user
	 * @return bool
	 */
	private function resetSecretCodes( $user ) {
		$user->setOption( self::OPT_SECRET_SETUP, false );
		$user->setOption( self::OPT_SECRET, false );
		$user->setOption( self::OPT_RESCUE_1, false );
		$user->setOption( self::OPT_RESCUE_2, false );
		$user->setOption( self::OPT_RESCUE_3, false );
		$user->saveSettings();

		return true;
	}

	/**
	 * @return \PHPGangsta_GoogleAuthenticator
	 */
	private function getGoogleAuthClass() {
		return new \PHPGangsta_GoogleAuthenticator();
	}
}
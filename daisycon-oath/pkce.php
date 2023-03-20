<?php

/**
 * PKCE generator
 */
final class Pkce
{
	/**
	 * Code
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Initiate random string
	 *
	 */
	public function __construct()
	{
		$this->code = $this->randomString(\random_int(43, 128));
	}

	/**
	 * Get code verifier
	 *
	 * @return string
	 */
	public function getCodeVerifier(): string
	{
		return $this->code;
	}

	/**
	 * Get challenge
	 *
	 * @return string
	 */
	public function getCodeChallenge(): string
	{
		return $this->hash();
	}

	/**
	 * Get hash
	 *
	 * @return string
	 */
	private function hash(): string
	{
		return \str_replace('=', '', \strtr(\base64_encode(\hash('sha256', $this->code, true)), '+/', '-_'));
	}

	/**
	 * PKCE verifier
	 *
	 * @param string $challenge
	 * @return bool
	 */
	public function verify(string $challenge): bool
	{
		return $this->hash() === $challenge;
	}

	/**
	 * Random string
	 *
	 * @param int $length
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	private function randomString(int $length): string
	{
		if ($length < 1)
		{
			throw new InvalidArgumentException('Length must be a positive integer');
		}
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-._~';
		$length = strlen($chars) - 1;
		$out = '';
		for ($i  =0; $i < $length; ++$i)
		{
			$out .= $chars[\random_int(0, $length)];
		}

		return $out;
	}

	/**
	 * Get the method
	 *
	 * @return string
	 */
	public function getMethod(): string
	{
		return 'S256';
	}

}

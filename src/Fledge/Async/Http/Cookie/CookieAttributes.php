<?php declare(strict_types=1);

namespace Fledge\Async\Http\Cookie;

/**
 * Cookie attributes as defined in https://tools.ietf.org/html/rfc6265.
 *
 * @link https://tools.ietf.org/html/rfc6265
 */
final class CookieAttributes implements \Stringable
{
    public const string SAMESITE_NONE = 'None';
    public const string SAMESITE_LAX = 'Lax';
    public const string SAMESITE_STRICT = 'Strict';

    /**
     * @return CookieAttributes No cookie attributes.
     *
     * @see self::default()
     */
    public static function empty(): self
    {
        $new = new self;
        $new->httpOnly = false;

        return $new;
    }

    /**
     * @return CookieAttributes Default cookie attributes, which means httpOnly is enabled by default.
     *
     * @see self::empty()
     */
    public static function default(): self
    {
        return new self;
    }

    private string $path = '';

    private string $domain = '';

    private ?int $maxAge = null;

    private ?\DateTimeImmutable $expiry = null;

    private bool $secure = false;

    private bool $httpOnly = true;

    private ?string $sameSite = null;

    private function __construct()
    {
        // only allow creation via named constructors
    }

    /**
     * @param string $path Cookie path.
     *
     * @return self Cloned instance with the specified operation applied. Cloned instance with the specified operation
     *     applied.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.4
     */
    public function withPath(string $path): self
    {
        return clone($this, ['path' => $path]);
    }

    /**
     * @param string $domain Cookie domain.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.3
     */
    public function withDomain(string $domain): self
    {
        return clone($this, ['domain' => $domain]);
    }

    /**
     * @param string $sameSite Cookie SameSite attribute value.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @link https://tools.ietf.org/html/draft-ietf-httpbis-rfc6265bis-03#section-5.3.7
     */
    public function withSameSite(string $sameSite): self
    {
        $normalizedValue = \ucfirst(\strtolower($sameSite));
        if (!\in_array($normalizedValue, [self::SAMESITE_NONE, self::SAMESITE_LAX, self::SAMESITE_STRICT], true)) {
            throw new \Error("Invalid SameSite attribute: " . $sameSite);
        }

        return clone($this, ['sameSite' => $normalizedValue]);
    }

    /**
     * @return self Cloned instance with the specified operation applied.
     *
     * @link https://tools.ietf.org/html/draft-ietf-httpbis-rfc6265bis-03#section-5.3.7
     */
    public function withoutSameSite(): self
    {
        return clone($this, ['sameSite' => null]);
    }

    /**
     * Applies the given maximum age to the cookie.
     *
     * @param int $maxAge Cookie maximum age.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withoutMaxAge()
     * @see self::withExpiry()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.2
     */
    public function withMaxAge(int $maxAge): self
    {
        return clone($this, ['maxAge' => $maxAge]);
    }

    /**
     * Removes any max-age information.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withMaxAge()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.2
     */
    public function withoutMaxAge(): self
    {
        return clone($this, ['maxAge' => null]);
    }

    /**
     * Applies the given expiry to the cookie.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withMaxAge()
     * @see self::withoutExpiry()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.1
     */
    public function withExpiry(\DateTimeInterface $date): self
    {
        return clone($this, ['expiry' => \DateTimeImmutable::createFromInterface($date)]);
    }

    /**
     * Removes any expiry information.
     *
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withExpiry()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.1
     */
    public function withoutExpiry(): self
    {
        return clone($this, ['expiry' => null]);
    }

    /**
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withoutSecure()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.5
     */
    public function withSecure(): self
    {
        return clone($this, ['secure' => true]);
    }

    /**
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withSecure()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.5
     */
    public function withoutSecure(): self
    {
        return clone($this, ['secure' => false]);
    }

    /**
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withoutHttpOnly()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.6
     */
    public function withHttpOnly(): self
    {
        return clone($this, ['httpOnly' => true]);
    }

    /**
     * @return self Cloned instance with the specified operation applied.
     *
     * @see self::withHttpOnly()
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.6
     */
    public function withoutHttpOnly(): self
    {
        return clone($this, ['httpOnly' => false]);
    }

    /**
     * @return string Cookie path.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.4
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return string Cookie domain.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.3
     */
    public function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * @return string Cookie domain.
     *
     * @link https://tools.ietf.org/html/draft-ietf-httpbis-rfc6265bis-03#section-5.3.7
     */
    public function getSameSite(): ?string
    {
        return $this->sameSite;
    }

    /**
     * @return int|null Cookie maximum age in seconds or `null` if no value is set.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.2
     */
    public function getMaxAge(): ?int
    {
        return $this->maxAge;
    }

    /**
     * @return \DateTimeImmutable|null Cookie expiry or `null` if no value is set.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.2
     */
    public function getExpiry(): ?\DateTimeImmutable
    {
        return $this->expiry;
    }

    /**
     * @return bool Whether the secure flag is enabled or not.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.5
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * @return bool Whether the httpOnly flag is enabled or not.
     *
     * @link https://tools.ietf.org/html/rfc6265#section-5.2.6
     */
    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    /**
     * @return string Representation of the cookie attributes appended to key=value in a 'set-cookie' header.
     */
    public function toString(): string
    {
        $string = '';

        if ($this->expiry) {
            $string .= '; Expires=' . \gmdate('D, j M Y G:i:s T', $this->expiry->getTimestamp());
        }

        /** @psalm-suppress RiskyTruthyFalsyComparison */
        if ($this->maxAge) {
            $string .= '; Max-Age=' . $this->maxAge;
        }

        if ('' !== $this->path) {
            $string .= '; Path=' . $this->path;
        }

        if ('' !== $this->domain) {
            $string .= '; Domain=' . $this->domain;
        }

        if ($this->secure) {
            $string .= '; Secure';
        }

        if ($this->httpOnly) {
            $string .= '; HttpOnly';
        }

        if ($this->sameSite !== null) {
            $string .= '; SameSite=' . $this->sameSite;
        }

        return $string;
    }

    /**
     * @see toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}

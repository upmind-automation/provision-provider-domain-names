<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Demo;

use Carbon\CarbonImmutable;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Support\Str;
use Upmind\ProvisionProviders\DomainNames\Data\ContactData;
use Upmind\ProvisionProviders\DomainNames\Data\DomainResult;
use Upmind\ProvisionProviders\DomainNames\Data\NameserversParams;
use Upmind\ProvisionProviders\DomainNames\Demo\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Helper\Utils;

class DomainFaker
{
    protected Generator $faker;

    protected string $domain;
    protected ?string $domainId = null;
    protected ?bool $canRegister = null;
    protected ?array $statuses = null;
    protected ?bool $locked = null;
    protected ?ContactData $registrant = null;
    protected ?ContactData $admin = null;
    protected ?string $nsHost = null;
    protected ?string $eppCode = null;
    protected ?CarbonImmutable $createdAt = null;
    protected ?CarbonImmutable $updatedAt = null;
    protected ?CarbonImmutable $expiresAt = null;
    protected ?int $regPeriod = null;

    public function __construct(Configuration $configuration, string $sld, string $tld)
    {
        $this->domain = Utils::getDomain($sld, $tld);
        $this->faker = Factory::create();
        $this->faker->seed(preg_replace('/[^\d]/', '', md5($configuration->api_token . '.' . $this->domain)));

        // generate data now to ensure consistency regardless of order of calls
        $this->canRegister();
        $this->isLocked();
        $this->getRegPeriod();
        $this->getDomainId();
        $this->getStatuses();
        $this->getRegistrant();
        $this->getAdmin();
        $this->getNameservers();
        $this->getEppCode();
        $this->getCreatedAt();
        $this->getUpdatedAt();
        $this->getExpiresAt();
    }

    public function getFaker(): Generator
    {
        return $this->faker;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getTld(): string
    {
        return Utils::getTld($this->getDomain());
    }

    public function canRegister(): bool
    {
        return $this->canRegister ??= $this->faker->boolean(50);
    }

    /**
     * @return string[]
     */
    public function getStatuses(): array
    {
        return $this->statuses ??= [
            $this->getExpiresAt()->isPast() ? 'Expired' : 'Active',
            $this->faker->randomElement([
                'serverTransferProhibited',
                'serverUpdateProhibited',
                'serverDeleteProhibited'
            ]),
        ];
    }

    public function getDomainId(): string
    {
        return $this->domainId ??= (string)$this->faker->randomNumber(8, true);
    }

    public function isLocked(): bool
    {
        return $this->locked ??= $this->faker->boolean(80);
    }

    public function getRegistrant(): ContactData
    {
        return $this->registrant ??= ContactData::create()
            ->setName($this->faker->firstName() . ' ' . $this->faker->lastName())
            ->setEmail($this->faker->email())
            ->setPhone($this->faker->e164PhoneNumber())
            ->setAddress1($this->faker->streetAddress())
            ->setCity($this->faker->city())
            ->setState('') // Empty state.
            ->setPostcode($this->faker->postcode())
            ->setCountryCode($this->faker->countryCode());
    }

    public function getAdmin(): ContactData
    {
        return $this->admin ??= ContactData::create()
            ->setOrganisation($company = $this->faker->company())
            ->setEmail(sprintf('admin@%s.com', Str::slug($company)))
            ->setPhone($this->faker->e164PhoneNumber())
            ->setAddress1($this->faker->streetAddress())
            ->setCity($this->faker->city())
            ->setState('') // Empty state.
            ->setPostcode($this->faker->postcode())
            ->setCountryCode($this->faker->countryCode());
    }

    public function getNameservers(): NameserversParams
    {
        $this->nsHost ??= $this->faker->domainName();

        return NameserversParams::create()
            ->setNs1(['host' => 'ns1.' . $this->nsHost])
            ->setNs2(['host' => 'ns2.' . $this->nsHost])
            ->setNs3(['host' => 'ns3.' . $this->nsHost]);
    }

    public function getEppCode(): ?string
    {
        if (Str::endsWith($this->domain, '.uk')) {
            return 'N/A';
        }

        return $this->eppCode ??= $this->faker->password(6, 10);
    }

    public function getRegPeriod(): int
    {
        return $this->regPeriod ??= $this->faker->numberBetween(1, 3);
    }

    public function getCreatedAt(): CarbonImmutable
    {
        return $this->createdAt ??= CarbonImmutable::parse(
            $this->faker->dateTimeBetween('-2 year', 'today')
        );
    }

    public function getUpdatedAt(): CarbonImmutable
    {
        return $this->updatedAt ??= CarbonImmutable::parse(
            $this->faker->dateTimeBetween($this->getCreatedAt(), 'today')
        );
    }

    public function getExpiresAt(): CarbonImmutable
    {
        return $this->expiresAt ??= $this->getCreatedAt()->addYears($this->getRegPeriod());
    }

    public function getDomainResult(): DomainResult
    {
        return DomainResult::create()
            ->setId($this->getDomainId())
            ->setDomain($this->getDomain())
            ->setExpiresAt($this->getExpiresAt())
            ->setStatuses($this->getStatuses())
            ->setLocked($this->isLocked())
            ->setNs($this->getNameservers())
            ->setRegistrant($this->getRegistrant())
            ->setAdmin($this->getAdmin())
            ->setBilling($this->getAdmin())
            ->setTech($this->getAdmin())
            ->setCreatedAt($this->getCreatedAt())
            ->setUpdatedAt($this->getUpdatedAt());
    }
}

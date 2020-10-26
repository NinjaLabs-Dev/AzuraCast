<?php

namespace App\Entity;

use Aws\S3\S3Client;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use League\Flysystem\Adapter\Local;
use League\Flysystem\AdapterInterface;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use OpenApi\Annotations as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Table(name="storage_location")
 * @ORM\Entity()
 *
 * @OA\Schema(type="object", schema="StorageLocation")
 *
 * @AuditLog\Auditable
 */
class StorageLocation
{
    use Traits\TruncateStrings;

    public const TYPE_BACKUP = 'backup';
    public const TYPE_STATION_MEDIA = 'station_media';
    public const TYPE_STATION_RECORDINGS = 'station_recordings';

    public const ADAPTER_LOCAL = 'local';
    public const ADAPTER_S3 = 's3';

    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     *
     * @var int|null
     */
    protected $id;

    /**
     * @ORM\Column(name="type", type="string", length=50)
     *
     * @Assert\Choice(choices={
     *     StorageLocation::TYPE_BACKUP,
     *     StorageLocation::TYPE_STATION_MEDIA,
     *     StorageLocation::TYPE_STATION_RECORDINGS
     * })
     * @OA\Property(example="station_media")
     * @var string The type of storage location.
     */
    protected $type;

    /**
     * @ORM\Column(name="adapter", type="string", length=50)
     *
     * @Assert\Choice(choices={StorageLocation::ADAPTER_LOCAL, StorageLocation::ADAPTER_S3})
     * @OA\Property(example="local")
     * @var string The storage adapter to use for this location.
     */
    protected $adapter = self::ADAPTER_LOCAL;

    /**
     * @ORM\Column(name="adapter", type="string", length=255, nullable=true)
     *
     * @OA\Property(example="/var/azuracast/stations/azuratest_radio/media")
     * @var string|null The local path, if the local adapter is used, or path prefix for S3/remote adapters.
     */
    protected $path;

    /**
     * @ORM\Column(name="s3_credential_key", type="string", length=255, nullable=true)
     *
     * @OA\Property(example="your-key-here")
     * @var string|null The credential key for S3 adapters.
     */
    protected $s3CredentialKey;

    /**
     * @ORM\Column(name="s3_credential_secret", type="string", length=255, nullable=true)
     *
     * @OA\Property(example="your-secret-here")
     * @var string|null The credential secret for S3 adapters.
     */
    protected $s3CredentialSecret;

    /**
     * @ORM\Column(name="s3_region", type="string", length=150, nullable=true)
     *
     * @OA\Property(example="your-region")
     * @var string|null The region for S3 adapters.
     */
    protected $s3Region;

    /**
     * @ORM\Column(name="s3_version", type="string", length=150, nullable=true)
     *
     * @OA\Property(example="latest")
     * @var string|null The API version for S3 adapters.
     */
    protected $s3Version = 'latest';

    /**
     * @ORM\Column(name="s3_bucket", type="string", length=255, nullable=true)
     *
     * @OA\Property(example="your-bucket-name")
     * @var string|null The S3 bucket name for S3 adapters.
     */
    protected $s3Bucket = null;

    /**
     * @ORM\Column(name="s3_endpoint", type="string", length=255, nullable=true)
     *
     * @OA\Property(example="https://your-region.digitaloceanspaces.com")
     * @var string|null The optional custom S3 endpoint S3 adapters.
     */
    protected $s3Endpoint = null;

    /**
     * @ORM\OneToMany(targetEntity="Media", mappedBy="storage_location")
     * @var Collection|Media[]
     */
    protected $media;

    public function __construct(string $type, string $adapter)
    {
        $this->type = $type;
        $this->adapter = $adapter;

        $this->media = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAdapter(): string
    {
        return $this->adapter;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): void
    {
        $this->path = $this->truncateString($path, 255);
    }

    public function getS3CredentialKey(): ?string
    {
        return $this->s3CredentialKey;
    }

    public function setS3CredentialKey(?string $s3CredentialKey): void
    {
        $this->s3CredentialKey = $this->truncateString($s3CredentialKey, 255);
    }

    public function getS3CredentialSecret(): ?string
    {
        return $this->s3CredentialSecret;
    }

    public function setS3CredentialSecret(?string $s3CredentialSecret): void
    {
        $this->s3CredentialSecret = $this->truncateString($s3CredentialSecret, 255);
    }

    public function getS3Region(): ?string
    {
        return $this->s3Region;
    }

    public function setS3Region(?string $s3Region): void
    {
        $this->s3Region = $s3Region;
    }

    public function getS3Version(): ?string
    {
        return $this->s3Version;
    }

    public function setS3Version(?string $s3Version): void
    {
        $this->s3Version = $s3Version;
    }

    public function getS3Bucket(): ?string
    {
        return $this->s3Bucket;
    }

    public function setS3Bucket(?string $s3Bucket): void
    {
        $this->s3Bucket = $s3Bucket;
    }

    public function getS3Endpoint(): ?string
    {
        return $this->s3Endpoint;
    }

    public function setS3Endpoint(?string $s3Endpoint): void
    {
        $this->s3Endpoint = $this->truncateString($s3Endpoint, 255);
    }

    /**
     * @return Station[]|Collection
     */
    public function getStations()
    {
        return $this->stations;
    }

    /**
     * @return Media[]|Collection
     */
    public function getMedia()
    {
        return $this->media;
    }

    public function getStorageAdapter(?string $suffix = null): AdapterInterface
    {
        $path = $this->path . $suffix;

        switch ($this->adapter) {
            case self::ADAPTER_S3:
                $s3Options = array_filter([
                    'credentials' => [
                        'key' => $this->s3CredentialKey,
                        'secret' => $this->s3CredentialSecret,
                    ],
                    'region' => $this->s3Region,
                    'version' => $this->s3Version,
                    'endpoint' => $this->s3Endpoint,
                ]);

                $client = new S3Client($s3Options);
                return new AwsS3Adapter($client, $this->s3Bucket, $path);

            case self::ADAPTER_LOCAL:
            default:
                return new Local($path);
        }
    }
}

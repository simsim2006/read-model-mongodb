<?php

declare(strict_types=1);

namespace Broadway\ReadModel\MongoDB;

use Assert\Assertion as Assert;
use Broadway\ReadModel\Identifiable;
use Broadway\ReadModel\Repository;
use Broadway\Serializer\Serializer;
use MongoDB\Collection;
use MongoDB\Model\BSONDocument;

/**
 * @author Robin van der Vleuten <robin@webstronauts.co>
 */
class MongoDBRepository implements Repository
{
    /**
     * @var Collection
     */
    private $collection;

    /**
     * @var Serializer
     */
    private $serializer;

    /**
     * @var string
     */
    private $class;

    public function __construct(Collection $collection, Serializer $serializer, string $class)
    {
        $this->collection = $collection;
        $this->serializer = $serializer;
        $this->class = $class;
    }

    /**
     * {@inheritdoc}
     */
    public function save(Identifiable $model): void
    {
        Assert::isInstanceOf($model, $this->class);

        $normalized = $this->normalizeIdentifiable($model);

        $this->collection->updateOne(['_id' => $normalized['_id']], ['$set' => $normalized], ['upsert' => true]);
    }

    /**
     * {@inheritdoc}
     */
    public function find($id): ?Identifiable
    {
        $document = $this->collection->findOne(['_id' => (string) $id]);

        return $document ? $this->denormalizeIdentifiable($document) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function findBy(array $fields): array
    {
        if (empty($fields)) {
            return [];
        }

        return $this->findModelsByQuery($fields);
    }

    /**
     * {@inheritdoc}
     */
    public function findAll(): array
    {
        return $this->findModelsByQuery();
    }

    /**
     * {@inheritdoc}
     */
    public function remove($id): void
    {
        $this->collection->deleteOne(['_id' => (string) $id]);
    }

    /**
     * @param array $query
     *
     * @return Identifiable[]
     */
    private function findModelsByQuery(array $query = []): array
    {

        // ensure value for _id is a string
        array_walk($query, function (&$value, $key) {
            if ($key === '_id') {
                $value = (string) $value;
            }
        });

        return array_map(function ($document) {
            return $this->denormalizeIdentifiable($document);
        }, $this->collection->find($query)->toArray());
    }

    /**
     * @param Identifiable $model
     *
     * @return array
     */
    private function normalizeIdentifiable(Identifiable $model)
    {
        $serialized = $this->serializer->serialize($model);

        return array_reduce(array_keys($serialized['payload']), function ($normalized, $key) use ($serialized) {
            return array_merge($normalized, [ $key === 'id' ? '_id' : $key => $serialized['payload'][$key] ]);
        }, []);
    }

    private function denormalizeIdentifiable(BSONDocument $document): Identifiable
    {
        // Tiny hack to convert BSON types to PHP types.
        // I thought that I can use https://secure.php.net/manual/en/function.mongodb.bson-tophp.php here,
        // but apparently this method does not handle nested BSON types very well.
        $data = json_decode(json_encode($document), true);

        $payload = array_reduce(array_keys($data), function ($payload, $key) use ($data) {
            return array_merge($payload, [ $key => $data[$key] ]);
        }, ['_id' => $data['_id']]);

        return $this->serializer->deserialize([
            '_id' => $data['_id'],
            'class' => $this->class,
            'payload' => $payload,
        ]);
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }
}

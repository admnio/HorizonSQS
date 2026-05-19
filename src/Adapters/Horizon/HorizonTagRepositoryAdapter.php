<?php

namespace Admnio\Sunset\Adapters\Horizon;

use Admnio\Sunset\Contracts\TagRepository as SunsetTagRepo;
use Laravel\Horizon\Contracts\TagRepository as HorizonTagRepository;

class HorizonTagRepositoryAdapter implements HorizonTagRepository
{
    public function __construct(private SunsetTagRepo $tags)
    {
    }

    public function monitoring()
    {
        return $this->tags->monitored();
    }

    public function monitored(array $tags)
    {
        return array_values(array_intersect($tags, $this->tags->monitored()));
    }

    public function monitor($tag)
    {
        $this->tags->monitor($tag);
    }

    public function stopMonitoring($tag)
    {
        $this->tags->stopMonitoring($tag);
    }

    public function add($id, array $tags)
    {
        $this->tags->addPermanent($id, $tags);
    }

    public function addTemporary($minutes, $id, array $tags)
    {
        $expiresAt = time() + ((int) $minutes * 60);
        $this->tags->addTemporary($expiresAt, $id, $tags);
    }

    public function count($tag)
    {
        return $this->tags->count($tag);
    }

    public function jobs($tag)
    {
        return $this->tags->jobs($tag, null)->all();
    }

    public function paginate($tag, $startingAt = 0, $limit = 25)
    {
        $afterIndex = $startingAt > 0 ? (string) ($startingAt - 1) : null;
        return [
            'jobs' => $this->tags->jobs($tag, $afterIndex)->all(),
            'total' => $this->tags->count($tag),
        ];
    }

    public function forget($tag)
    {
        $this->tags->forget($tag);
    }
}

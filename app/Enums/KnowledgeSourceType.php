<?php

namespace App\Enums;

use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The kind of record a knowledge passage was cut from, as cited in search
 * results.
 */
enum KnowledgeSourceType: string
{
    case DOCUMENT = 'document';
    case ARTICLE = 'article';
    case POLICY = 'policy';

    public static function fromModel(Model $model): self
    {
        return match (true) {
            $model instanceof KnowledgeDocument => self::DOCUMENT,
            $model instanceof KnowledgeBaseArticle => self::ARTICLE,
            $model instanceof HotelPolicy => self::POLICY,
            default => throw new InvalidArgumentException('Not a knowledge source: '.$model::class),
        };
    }
}

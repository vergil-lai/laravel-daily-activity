<?php

declare(strict_types=1);

namespace VergilLai\DailyActivity\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;

/** @implements CastsAttributes<string, string> */
class BinaryDays implements CastsAttributes
{
    /**
     * 将数据库返回的字符串或流转换为 46 字节位图，并恢复流的原始读取位置。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (is_resource($value)) {
            $position = ftell($value);
            rewind($value);
            $bytes = stream_get_contents($value);

            if ($position !== false) {
                fseek($value, $position);
            }
            $value = $bytes;
        }

        if (! is_string($value) || strlen($value) !== 46) {
            throw new InvalidArgumentException('The days bitmap must contain exactly 46 bytes.');
        }

        return $value;
    }

    /**
     * 校验位图并转换为可用于数据库二进制参数绑定的内存流。
     *
     * @param  array<string, mixed>  $attributes
     * @return resource
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return self::binding($this->get($model, $key, $value, $attributes));
    }

    /**
     * 将 46 字节位图写入内存流并将指针归零，供数据库作为二进制参数绑定。
     *
     * @return resource
     */
    public static function binding(string $bytes): mixed
    {
        if (strlen($bytes) !== 46) {
            throw new InvalidArgumentException('The days bitmap must contain exactly 46 bytes.');
        }

        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to allocate the bitmap binding.');
        }
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}

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
     * @param  Model  $model  正在进行属性转换的模型实例。
     * @param  string  $key  当前转换属性的名称。
     * @param  mixed  $value  待转换的原始属性值，支持 46 字节字符串或可读取的流资源。
     * @param  array<string, mixed>  $attributes  模型当前的原始属性集合。
     * @return string 校验后的 46 字节活动位图字符串。
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
     * @param  Model  $model  正在进行属性转换的模型实例。
     * @param  string  $key  当前转换属性的名称。
     * @param  mixed  $value  待转换的原始属性值，支持 46 字节字符串或可读取的流资源。
     * @param  array<string, mixed>  $attributes  模型当前的原始属性集合。
     * @return resource 可用于数据库二进制参数绑定、读取指针位于起点的内存流资源。
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return self::binding($this->get($model, $key, $value, $attributes));
    }

    /**
     * 将 46 字节位图写入内存流并将指针归零，供数据库作为二进制参数绑定。
     *
     * @param  string  $bytes  用于数据库二进制参数绑定的 46 字节活动位图。
     * @return resource 包含活动位图、读取指针位于起点的内存流资源。
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

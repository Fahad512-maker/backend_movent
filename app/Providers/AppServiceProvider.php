<?php

namespace App\Providers;


use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Column types MySQL refuses to give a *literal* DEFAULT to (error 1101:
     * "BLOB, TEXT, GEOMETRY or JSON column can't have a default value").
     * MariaDB — what XAMPP ships locally — happily accepts the literal, so a
     * migration written and tested on the dev machine only explodes once it
     * reaches Railway's real MySQL.
     */
    private const EXPRESSION_DEFAULT_TYPES = [
        'json', 'jsonb',
        'text', 'tinyText', 'mediumText', 'longText',
        'binary',
        'geometry', 'geography', 'geometryCollection',
        'point', 'multiPoint', 'lineString', 'multiLineString',
        'polygon', 'multiPolygon', 'multiPolygonZ',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->forcePortableBlobDefaults();
    }

    /**
     * Keep schema DDL portable between the local MariaDB and Railway's MySQL.
     *
     * Both engines accept an *expression* default — `DEFAULT ('[7,3,1]')` —
     * on the types listed above, even when the expression is just a literal.
     * Only the bare literal form is MariaDB-only. So instead of asking every
     * future migration to remember the difference, rewrite the literal into
     * the parenthesised form on the way in; `$table->json('x')->default(...)`
     * then produces identical, valid DDL on both.
     *
     * Requires MySQL 8.0.13+ or MariaDB 10.2+ (both predate expression
     * defaults being usable); Railway provisions MySQL 8+, XAMPP 8.x ships
     * MariaDB 10.4+.
     */
    private function forcePortableBlobDefaults(): void
    {
        ColumnDefinition::macro('default', function ($value) {
            /** @var \Illuminate\Database\Schema\ColumnDefinition $this */
            if (! is_null($value)
                && ! $value instanceof Expression
                && in_array($this->type, AppServiceProvider::blobDefaultTypes(), true)) {
                $value = new Expression(
                    "('".str_replace("'", "''", AppServiceProvider::stringifyDefault($value))."')"
                );
            }

            $this->offsetSet('default', $value);

            return $this;
        });
    }

    /**
     * Exposed for the macro closure, which is rebound to ColumnDefinition and
     * so cannot reach this provider's private constant directly.
     */
    public static function blobDefaultTypes(): array
    {
        return self::EXPRESSION_DEFAULT_TYPES;
    }

    /**
     * Render a default value the way the schema grammar would have, so arrays
     * passed to a json column land as JSON rather than "Array".
     */
    public static function stringifyDefault(mixed $value): string
    {
        return match (true) {
            is_bool($value) => (string) (int) $value,
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}

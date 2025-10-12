<?php

declare(strict_types=1);

namespace Phan\Tests;

use Phan\Config;
use Phan\Language\Type\NullType;
use PHPUnit\Framework\TestCase;

/**
 * Any common initialization or configuration should go here
 * (E.g. this changes https://phpunit.de/manual/current/en/fixtures.html#fixtures.global-state for some classes)
 */
abstract class TestBase extends TestCase
{
    /**
     * @suppress PhanAccessMethodInternal
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Need more than 1G to generate code coverage reports
        \ini_set('memory_limit', '2G');
        \chdir(\dirname(__DIR__, 2));
        Config::reset();
        // HACK: Force instantiation of a Type instance so that Type::$canonical_object_map is not empty. This way,
        // PHPUnit will understand that the property cannot be backed up (see
        // SebastianBergmann\GlobalState\Snapshot::canBeSerialized; trying to serialize Type will throw) and
        // leave it alone. Alternatively, we would have to add the ExcludeStaticPropertyFromBackup attribute to
        // basically every test class in phan (attributes are not inherited by subclasses).
        NullType::instance(false);
    }
}

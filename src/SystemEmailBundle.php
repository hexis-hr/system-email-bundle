<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Bundle entry point.
 *
 * Register in `config/bundles.php`:
 *
 *   return [
 *       Hexis\SystemEmailBundle\SystemEmailBundle::class => ['all' => true],
 *   ];
 *
 * The parent {@see Bundle} auto-discovers the container extension at
 * {@see \Hexis\SystemEmailBundle\DependencyInjection\SystemEmailExtension} by
 * stripping the "Bundle" suffix from this class's short name.
 *
 * Classic Bundle/Extension/Configuration style is used deliberately (not
 * AbstractBundle) to keep support down to Symfony 5.4.
 */
final class SystemEmailBundle extends Bundle
{
    /**
     * Point resource resolution (`@SystemEmailBundle/config/...` and the
     * `@SystemEmail` Twig namespace) at the bundle root rather than src/,
     * since config/ and templates/ live there.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}

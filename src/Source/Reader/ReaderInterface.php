<?php
/**
 * This file is part of the browscap-helper package.
 *
 * Copyright (c) 2015-2020, Thomas Mueller <mimmi20@live.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);
namespace BrowscapHelper\Source\Reader;

interface ReaderInterface
{
    /**
     * @param string $file
     *
     * @return void
     */
    public function addLocalFile(string $file): void;

    /**
     * @return iterable
     */
    public function getAgents(): iterable;
}

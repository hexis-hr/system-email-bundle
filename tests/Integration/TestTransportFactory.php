<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Tests\Integration;

use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class TestTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        $transport = new TestTransport($this->dispatcher, $this->logger);
        $transport->down = $dsn->getHost() === 'down';

        return $transport;
    }

    protected function getSupportedSchemes(): array
    {
        return ['test'];
    }
}

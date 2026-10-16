<?php
declare(strict_types=1);

namespace Magelan\Omnisend\Model\Queue;

use Magelan\Omnisend\Model\Api\ApiException;

interface HandlerInterface
{
    /**
     * Sends one queue row to Omnisend.
     *
     * @param array<string, mixed> $row queue row, payload already decoded
     * @return string|null a note kept on the row (e.g. "skipped: order not found"), null when plainly sent
     * @throws ApiException when Omnisend refused the call or could not be reached
     */
    public function handle(array $row): ?string;
}

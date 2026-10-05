<?php

declare(strict_types=1);

namespace PrintScript\Google;

/**
 * Alles wat het netwerk raakt, achter één deurtje.
 *
 * Daardoor kunnen de tests een heel gesprek met Google naspelen — een
 * verlopen token, een 403, drie keer 503 gevolgd door succes — zonder dat er
 * ooit een verbinding opengaat.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @param ?array<string, string> $form null = GET, anders POST met formuliervelden
     * @return Response
     */
    public function send(string $url, array $headers = [], ?array $form = null): Response;
}

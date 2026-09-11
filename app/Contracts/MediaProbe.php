<?php

namespace App\Contracts;

interface MediaProbe
{
    /** @return array{duration_ms:int,mime:string,width:?int,height:?int} */
    public function inspect(string $absolutePath): array;
}

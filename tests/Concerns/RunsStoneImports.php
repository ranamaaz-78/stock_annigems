<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * RunsStoneImports
 *
 * Drives the batched import the way the browser does: begin, one request
 * per batch, then finish with every row that was set aside.
 */
trait RunsStoneImports
{
    /**
     * Run a whole import and return the finish response.
     *
     * Rows go to the server in batches of $chunkSize; rows the server
     * rejects, plus any in $leftRed (rows the operator left red), are sent
     * back with the finish call.
     *
     * @param  list<array<string, mixed>>  $rows  Rows sent in batches
     * @param  list<array<string, mixed>>  $leftRed  Rows the browser never sent
     * @param  array<string, string>  $headers
     */
    protected function runImport(
        User $user,
        array $rows,
        string $filename = 'supplier.xlsx',
        array $leftRed = [],
        array $headers = [],
        int $chunkSize = 100,
        ?string $token = null,
        array $mapping = [],
    ): TestResponse {
        $begin = $this->actingAs($user)->postJson(route('branch.stones.import.begin'), [
            'filename' => $filename,
            'total_rows' => max(1, count($rows) + count($leftRed)),
        ])->assertCreated();

        $uuid = $begin->json('data.import_uuid');
        $rejected = $leftRed;

        foreach (array_chunk($rows, $chunkSize) as $index => $chunk) {
            $response = $this->actingAs($user)->postJson(route('branch.stones.import.chunk'), [
                'import_uuid' => $uuid,
                'chunk_index' => $index,
                'rows' => $chunk,
            ])->assertOk();

            $rejected = array_merge($rejected, $response->json('data.rejected'));
        }

        return $this->actingAs($user)->postJson(route('branch.stones.import.finish'), [
            'import_uuid' => $uuid,
            'rejected_rows' => $rejected,
            'headers' => $headers,
            'token' => $token,
            'mapping' => $mapping,
        ]);
    }
}

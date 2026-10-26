<?php

namespace App\Actions\Admin\EmailTemplate;

use App\Models\EmailTemplate;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class BulkDeleteEmailTemplatesAction
{
    /**
     * Delete multiple email templates in a single transaction, rejecting deletion if any protected system template is selected.
     *
     * @param  list<int>  $ids
     * @return int Number of deleted email templates
     *
     * @throws HttpResponseException
     */
    public function execute(array $ids): int
    {
        $hasProtectedTemplate = EmailTemplate::query()
            ->whereIn('id', $ids)
            ->whereIn('key', ['ADMIN_PASSWORD_RESET_OTP', 'USER_ACCOUNT_CREATED'])
            ->exists();

        if ($hasProtectedTemplate) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => 'The selection includes protected system templates which cannot be deleted.',
                    'error' => 'Protected template in selection',
                ], 422)
            );
        }

        try {
            return DB::transaction(function () use ($ids) {
                return EmailTemplate::query()->whereIn('id', $ids)->delete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'constraint')) {
                throw new HttpResponseException(
                    response()->json([
                        'status' => false,
                        'message' => 'Cannot delete selected templates because one or more are currently assigned to campaigns.',
                        'error' => 'Foreign key constraint',
                    ], 400)
                );
            }
            throw $e;
        }
    }
}

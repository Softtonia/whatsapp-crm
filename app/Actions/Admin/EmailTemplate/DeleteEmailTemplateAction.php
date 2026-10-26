<?php

namespace App\Actions\Admin\EmailTemplate;

use App\Models\EmailTemplate;
use Illuminate\Http\Exceptions\HttpResponseException;

class DeleteEmailTemplateAction
{
    /**
     * Delete the specified email template, protecting system-critical templates.
     *
     * @throws HttpResponseException
     */
    public function execute(EmailTemplate $emailTemplate): void
    {
        if (in_array($emailTemplate->key, ['ADMIN_PASSWORD_RESET_OTP', 'USER_ACCOUNT_CREATED'], true)) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => "System template {$emailTemplate->key} is protected and cannot be deleted.",
                    'error' => 'Protected template',
                ], 422)
            );
        }

        try {
            $emailTemplate->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'constraint')) {
                throw new HttpResponseException(
                    response()->json([
                        'status' => false,
                        'message' => 'Cannot delete this template because it is currently assigned to one or more campaigns.',
                        'error' => 'Foreign key constraint',
                    ], 400)
                );
            }
            throw $e;
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\ComplianceDocument;
use App\Models\Notification;
use App\Services\EventMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * "Compliance Document Expiring" — an approved document with an expires_at
 * silently lapsed before this: ComplianceCaseStatusService::recompute() would
 * eventually drop the case out of 'compliant', but nobody was warned in
 * advance. Runs daily (see routes/console.php).
 *
 * Fires once per document per threshold, so a 14-day window and a 3-day
 * window each get exactly one warning as the date approaches — the guard is
 * the notification row's own type, which carries the threshold in it.
 */
class NotifyExpiringComplianceDocuments extends Command
{
    protected $signature = 'compliance:notify-expiring {--days=14,3 : Comma-separated day thresholds to warn at}';

    protected $description = 'Warn compliance officers (in-app + email) about approved documents that are about to expire';

    public function handle(): int
    {
        $thresholds = collect(explode(',', (string) $this->option('days')))
            ->map(fn ($d) => (int) trim($d))
            ->filter(fn ($d) => $d > 0)
            ->unique()
            ->sortDesc()
            ->values();

        if ($thresholds->isEmpty()) {
            $this->error('No valid day thresholds given.');
            return self::FAILURE;
        }

        $sent = 0;

        foreach ($thresholds as $days) {
            $target = now()->addDays($days)->toDateString();

            $documents = ComplianceDocument::query()
                ->where('status', 'approved')
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', $target)
                ->with(['complianceCase.project:id,name', 'complianceCase.client:id,name'])
                ->get();

            foreach ($documents as $document) {
                $case = $document->complianceCase;
                if (!$case) continue;

                $type = "compliance_document_expiring_{$days}d";

                $alreadyNotified = Notification::where('type', $type)
                    ->where('entity_type', 'ComplianceDocument')
                    ->where('entity_id', $document->id)
                    ->exists();

                if ($alreadyNotified) continue;

                try {
                    $body = "\"{$document->original_name}\" expires on "
                        . $document->expires_at->format('d M Y') . " ({$days} day(s) away). Upload a new version to keep this case compliant.";

                    // Same recipient rule the Compliance controllers'
                    // notifyCase() uses: the case officer if there is one,
                    // otherwise the Company Admins.
                    if ($case->compliance_officer_id) {
                        Notification::create([
                            'user_id'     => $case->compliance_officer_id,
                            'company_id'  => $case->company_id,
                            'module'      => 'compliance',
                            'type'        => $type,
                            'title'       => 'Compliance document expiring',
                            'body'        => $body,
                            'entity_type' => 'ComplianceDocument',
                            'entity_id'   => $document->id,
                            'data'        => [
                                'project_id' => $case->project_id,
                                'link'       => "/compliance/projects/{$case->project_id}",
                            ],
                        ]);

                        $recipients = [['user_id' => $case->compliance_officer_id]];
                    } else {
                        // Company Admins aren't `users` rows, so their bell is
                        // fed through NotificationService's admin recipient.
                        \App\Services\NotificationService::notifyCompanyAdmins($case->company_id, null, [
                            'module'      => 'compliance',
                            'type'        => $type,
                            'title'       => 'Compliance document expiring',
                            'message'     => $body,
                            'entity_type' => 'ComplianceDocument',
                            'entity_id'   => $document->id,
                            'url'         => "/admin/compliance/projects/{$case->project_id}",
                        ]);

                        $recipients = EventMailService::companyAdminRecipients($case->company_id);
                    }

                    EventMailService::complianceDocumentExpiring($case, $document, $days, $recipients);
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('[compliance:notify-expiring] failed for document ' . $document->id . ': ' . $e->getMessage());
                }
            }
        }

        $this->info("Sent {$sent} expiry warning(s) across thresholds: {$thresholds->implode(', ')} day(s).");

        return self::SUCCESS;
    }
}

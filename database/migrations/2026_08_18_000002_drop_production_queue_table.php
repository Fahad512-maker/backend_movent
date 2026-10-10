<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// The Production Queue feature is removed entirely — it duplicated a chunk
// of Task.status behind a second, manually-synced state machine, which was
// the root cause of several bugs (stuck items with no way to approve, PM
// permission gaps). Task.status itself already carries ready_for_production/
// in_production, so nothing downstream needs this table anymore. Dropped
// outright per explicit decision, no archive — every row (production
// status history, priority ordering) is genuinely lost.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('production_queue');
    }

    public function down(): void
    {
        // Irreversible — table + all rows gone, no archive per decision.
    }
};

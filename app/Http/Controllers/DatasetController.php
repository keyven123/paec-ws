<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Dataset;
use Illuminate\Support\Facades\DB;

class DatasetController extends Controller
{
    public function getSiteVisit(Request $request)
    {
        $dataset = Dataset::firstOrCreate(
            ['name' => 'site_visit'],
            [
                'description' => 'Total Visit on the website',
                'value' => '0',
            ],
        );

        return response()->json([
            'name' => $dataset->name,
            'value' => $dataset->value,
        ]);
    }

    public function incrementSiteVisit(Request $request)
    {
        $dataset = Dataset::firstOrCreate(
            ['name' => 'site_visit'],
            [
                'description' => 'Total Visit on the website',
                'value' => 0,
            ],
        );

        Dataset::whereKey($dataset->id)->update([
            'value' => DB::raw("CAST(COALESCE(NULLIF(value, ''), '0') AS UNSIGNED) + 1"),
        ]);
        $dataset->refresh();

        return response()->json([
            'name' => $dataset->name,
            'value' => $dataset->value,
        ]);
    }
}

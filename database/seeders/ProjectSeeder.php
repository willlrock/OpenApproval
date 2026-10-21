<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $projects = [
            'Demo project',
        ];

        foreach ($projects as $name) {
            Project::firstOrCreate(['name' => $name]);
        }
    }
}

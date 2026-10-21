<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\Project;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            ['name' => 'Отдел маркетинга', 'is_active' => true],
            ['name' => 'Финансовый отдел', 'is_active' => true],
            ['name' => 'HR', 'is_active' => true],
            ['name' => 'Отдел снабжения', 'is_active' => true],
            ['name' => 'Отдел Аудита', 'is_active' => true],
            ['name' => 'Операционный отдел', 'is_active' => true],
            ['name' => 'Прочее', 'is_active' => true],
        ];

        $project = Project::first();

        foreach ($departments as $data) {
            $data['project_id'] = $project?->id;
            Department::firstOrCreate(['name' => $data['name']], $data);
        }
    }
}

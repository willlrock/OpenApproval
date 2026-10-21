<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DepartmentAdminController extends Controller
{
    public function departments()
    {
        $departments = Department::with('project:id,name')
            ->withCount('users')
            ->orderBy('project_id')
            ->orderBy('name')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'is_active' => $d->is_active,
                'project_id' => $d->project_id,
                'project_name' => $d->project?->name,
                'users_count' => $d->users_count,
            ]);

        $projects = Project::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Dashboard/Departments', [
            'departments' => $departments,
            'projects' => $projects,
        ]);
    }

    public function storeDepartment(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'project_id' => 'required|exists:projects,id',
        ], $this->validationMessages());

        Department::create([
            'name' => $request->name,
            'project_id' => $request->project_id,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Департамент создан');
    }

    public function updateDepartment(Request $request, $id)
    {
        $dept = Department::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:100',
            'project_id' => 'required|exists:projects,id',
        ], $this->validationMessages());

        $dept->update([
            'name' => $request->name,
            'project_id' => $request->project_id,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Департамент обновлён');
    }

    public function deleteDepartment($id)
    {
        Department::findOrFail($id)->delete();

        return back()->with('success', 'Департамент удалён');
    }

    private function validationMessages(): array
    {
        return [
            'name.required' => 'Укажите название департамента.',
            'name.string' => 'Название департамента должно быть текстом.',
            'name.max' => 'Название департамента должно содержать не больше 100 символов.',
            'project_id.required' => 'Выберите проект.',
            'project_id.exists' => 'Выбранный проект недоступен. Выберите другой проект.',
        ];
    }
}

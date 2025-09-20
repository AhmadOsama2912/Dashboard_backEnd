<?php

// app/Http/Controllers/User/UserScreenAssignController.php
namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Screen;
use App\Models\User;
use Illuminate\Http\Request;

class UserScreenAssignController extends Controller
{
    // mode = "company" => كل المشرفين يستطيعون الوصول
    // mode = "user" => ربط بشخص واحد (supervisor_id)
    public function assign(Request $request, Screen $screen)
    {
        $manager = $request->user();
        if ($manager->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);

        // الشاشة لازم تكون ضمن نفس الشركة
        if ((int) $screen->customer_id !== (int) $manager->customer_id) {
            return response()->json(['message'=>'Screen not in your customer scope'], 403);
        }

        $data = $request->validate([
            'mode' => ['required','in:company,user'],
            'supervisor_id' => ['nullable','integer','exists:users,id'],
        ]);

        if ($data['mode'] === 'company') {
            $screen->access_scope = 'company';
            $screen->assigned_user_id = null;
            $screen->save();
            return response()->json(['message'=>'Screen set to company scope','screen_id'=>$screen->id]);
        }

        // mode = user
        if (empty($data['supervisor_id'])) {
            return response()->json(['message'=>'supervisor_id is required for mode=user'], 422);
        }

        $sup = User::where('id', $data['supervisor_id'])
            ->where('customer_id', $manager->customer_id)
            ->where('role','supervisor')->first();

        if (!$sup) return response()->json(['message'=>'Supervisor not found in your customer'], 422);

        $screen->access_scope = 'user';
        $screen->assigned_user_id = $sup->id;
        $screen->save();

        return response()->json(['message'=>'Screen assigned to supervisor','screen'=>[
            'id'=>$screen->id,'access_scope'=>$screen->access_scope,'assigned_user_id'=>$screen->assigned_user_id
        ]]);
    }

    public function unassign(Request $request, Screen $screen)
    {
        $manager = $request->user();
        if ($manager->role !== 'manager') return response()->json(['message'=>'Only managers'], 403);

        if ((int) $screen->customer_id !== (int) $manager->customer_id) {
            return response()->json(['message'=>'Screen not in your customer scope'], 403);
        }

        $screen->access_scope = 'company';
        $screen->assigned_user_id = null;
        $screen->save();

        return response()->json(['message'=>'Screen set back to company scope','screen_id'=>$screen->id]);
    }
}

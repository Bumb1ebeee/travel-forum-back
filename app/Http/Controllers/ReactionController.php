<?php

namespace App\Http\Controllers;

use App\Models\Reaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReactionController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'reactable_id' => 'required|integer',
            'reactable_type' => 'required|string',
            'reaction' => 'required|in:like,dislike',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Проверяем, есть ли уже реакция
        $existingReaction = Reaction::where([
            'user_id' => $user->id,
            'reactable_id' => $request->reactable_id,
            'reactable_type' => $request->reactable_type,
        ])->first();

        if ($existingReaction) {
            if ($existingReaction->reaction === $request->reaction) {
                return response()->json(['message' => 'Reaction already exists', 'likes' => $this->getLikes($request)]);
            }
            // Если реакция другая, обновляем
            $existingReaction->update(['reaction' => $request->reaction]);
        } else {
            // Создаем новую реакцию
            Reaction::create([
                'user_id' => $user->id,
                'reactable_id' => $request->reactable_id,
                'reactable_type' => $request->reactable_type,
                'reaction' => $request->reaction,
            ]);
        }

        return response()->json([
            'message' => 'Reaction saved',
            'likes' => $this->getLikes($request),
        ]);
    }

    private function getLikes(Request $request)
    {
        $reactable = $request->reactable_type::find($request->reactable_id);
        return $reactable ? $reactable->likes : 0;
    }

    public function show(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $reaction = Reaction::where([
            'user_id' => $user->id,
            'reactable_id' => $request->reactable_id,
            'reactable_type' => $request->reactable_type,
        ])->first();

        return response()->json(['reaction' => $reaction ? $reaction->reaction : null]);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'reactable_id' => 'required|integer',
            'reactable_type' => 'required|string',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $reaction = Reaction::where([
            'user_id' => $user->id,
            'reactable_id' => $request->reactable_id,
            'reactable_type' => $request->reactable_type,
        ])->first();

        if ($reaction) {
            $reaction->delete();
        }

        return response()->json([
            'message' => 'Reaction removed',
            'likes' => $this->getLikes($request),
        ]);
    }
}

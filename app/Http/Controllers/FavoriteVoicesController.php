<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The speakers a member starred in the voice library, kept on their account
 * so they follow them to every device. The page also keeps a copy in the
 * browser, so starring still works until the column exists on a server.
 */
class FavoriteVoicesController extends Controller
{
    public const MAX = 100;

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'voices' => ['present', 'array', 'max:'.self::MAX],
            'voices.*' => ['string', 'max:60', 'regex:/^(voice|cloned)_\d+$/'],
        ]);
        $voices = array_values(array_unique($validated['voices']));

        if (! Schema::hasColumn('users', 'favorite_voices')) {
            return response()->json(['saved' => false, 'voices' => $voices]);
        }

        $user = $request->user();
        $user->forceFill(['favorite_voices' => $voices])->save();

        return response()->json(['saved' => true, 'voices' => $voices]);
    }

    /**
     * @return array<int, string>
     */
    public static function forUser(?User $user): array
    {
        $voices = $user?->getAttribute('favorite_voices');

        return is_array($voices) ? array_values(array_filter($voices, 'is_string')) : [];
    }
}

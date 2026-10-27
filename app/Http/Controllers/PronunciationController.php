<?php

namespace App\Http\Controllers;

use App\Models\Pronunciation;
use App\Support\Pronunciations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The studio's pronunciation panel: a member saves, changes and deletes their
 * own words (App\Support\Pronunciations). Every answer is the member's whole
 * list, newest first, as the panel shows it.
 */
class PronunciationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'word' => ['required', 'string', 'max:'.Pronunciations::WORD_MAX],
            'spoken' => ['required', 'string', 'max:'.Pronunciations::SPOKEN_MAX],
        ], [
            'word.required' => __('ui.dashboard.pron.error_empty'),
            'spoken.required' => __('ui.dashboard.pron.error_empty'),
            'word.max' => __('ui.dashboard.pron.error_long'),
            'spoken.max' => __('ui.dashboard.pron.error_long'),
        ]);
        $userId = (int) $request->user()->id;
        $word = trim((string) preg_replace('/\s+/u', ' ', $data['word']));
        $spoken = trim((string) preg_replace('/\s+/u', ' ', $data['spoken']));
        $key = Pronunciations::key($word);

        // A word, not a tag or a sentence: letters in it, no tag brackets, at most three words.
        foreach ([$word, $spoken] as $text) {
            if (! preg_match('/\p{L}/u', $text) || preg_match('/[<>\[\]]/u', $text) || count(explode(' ', $text)) > 3) {
                throw ValidationException::withMessages(['word' => __('ui.dashboard.pron.error_word')]);
            }
        }
        if ($word === $spoken) {
            throw ValidationException::withMessages(['spoken' => __('ui.dashboard.pron.error_same')]);
        }

        $editing = isset($data['id']) ? Pronunciation::query()->where('user_id', $userId)->find((int) $data['id']) : null;
        $sameWord = Pronunciation::query()->where('user_id', $userId)->where('word_key', $key)->first();
        if ($editing && $sameWord && $sameWord->id !== $editing->id) {
            // Changed into a word already on the list: that one takes the new pronunciation.
            $editing->delete();
            $editing = $sameWord;
        }
        $entry = $editing ?? $sameWord;
        if (! $entry && Pronunciation::query()->where('user_id', $userId)->count() >= Pronunciations::LIMIT) {
            throw ValidationException::withMessages(['word' => __('ui.dashboard.pron.error_full')]);
        }

        ($entry ?? new Pronunciation(['user_id' => $userId]))->fill(['word' => $word, 'word_key' => $key, 'spoken' => $spoken])->save();

        return response()->json(['items' => Pronunciations::panelItems($userId)]);
    }

    public function destroy(Request $request, int $pronunciation): JsonResponse
    {
        $userId = (int) $request->user()->id;
        Pronunciation::query()->where('user_id', $userId)->whereKey($pronunciation)->delete();

        return response()->json(['items' => Pronunciations::panelItems($userId)]);
    }
}

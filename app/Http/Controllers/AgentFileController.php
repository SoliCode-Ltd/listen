<?php

namespace App\Http\Controllers;

use App\Jobs\ReadAgentFile;
use App\Models\Agent;
use App\Models\AgentSource;
use App\Services\Agents\FileReader;
use App\Support\Agents\AgentInstructions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The files an agent's owner uploads in the Knowledge tab (a menu, a price list, a brochure):
 * kept on the private disk, read in the background (ReadAgentFile), and used on every call
 * (AgentInstructions). Only the agent's owner sees or changes them.
 */
class AgentFileController extends Controller
{
    public const MAX_FILES = 20;

    /** Each file at most 10 MB. */
    public const MAX_KB = 10_240;

    public function index(Request $request, Agent $agent): JsonResponse
    {
        $this->own($request, $agent);

        return response()->json($this->state($agent))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Agent $agent): JsonResponse
    {
        $this->own($request, $agent);
        $request->validate(['file' => ['required', 'file', 'max:'.self::MAX_KB]], [
            'file.max' => __('agents.files.too_big', ['mb' => intdiv(self::MAX_KB, 1024)]),
            'file.required' => __('agents.files.wrong_type'),
            'file.file' => __('agents.files.wrong_type'),
        ]);
        $file = $request->file('file');
        $ext = strtolower((string) $file->getClientOriginalExtension());
        // The name says what it is, and the file is what its name says.
        if (! isset(FileReader::TYPES[$ext]) || ! FileReader::looksLike($ext, (string) $file->getRealPath())) {
            throw ValidationException::withMessages(['file' => __('agents.files.wrong_type')]);
        }
        if ($this->uploads($agent)->count() >= self::MAX_FILES) {
            throw ValidationException::withMessages(['file' => __('agents.files.too_many', ['count' => self::MAX_FILES])]);
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $file->getClientOriginalName()));
        $name = Str::limit($name !== '' ? $name : 'file.'.$ext, 180, '');
        $path = $file->storeAs('agents/'.$agent->public_id, Str::random(40).'.'.$ext, AgentSource::DISK);
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['file' => __('agents.files.reasons.unreadable')]);
        }
        $source = AgentSource::create([
            'agent_id' => $agent->id, 'kind' => FileReader::TYPES[$ext], 'title' => $name, 'path' => $path,
            'status' => AgentSource::PENDING, 'chars' => 0,
        ]);
        ReadAgentFile::dispatch($source->id);

        return response()->json(['file' => $this->summary($source->fresh())] + $this->state($agent), 201);
    }

    /** What the agent learned from one file. */
    public function show(Request $request, Agent $agent, AgentSource $source): JsonResponse
    {
        $this->ownFile($request, $agent, $source);

        return response()->json(['file' => $this->summary($source), 'content' => (string) $source->content])->header('Cache-Control', 'no-store');
    }

    /** Read a file again (after "the reader is busy", or a file the owner has since fixed). */
    public function retry(Request $request, Agent $agent, AgentSource $source): JsonResponse
    {
        $this->ownFile($request, $agent, $source);
        $source->forceFill(['status' => AgentSource::PENDING, 'error' => null])->save();
        ReadAgentFile::dispatch($source->id);

        return response()->json(['file' => $this->summary($source->fresh())] + $this->state($agent));
    }

    public function destroy(Request $request, Agent $agent, AgentSource $source): JsonResponse
    {
        $this->ownFile($request, $agent, $source);
        $source->delete();

        return response()->json($this->state($agent));
    }

    /** @return array{files: list<array<string, mixed>>, used: int, budget: int, max_files: int} */
    private function state(Agent $agent): array
    {
        $files = $this->uploads($agent)->orderBy('id')->get();
        $inMind = collect(AgentInstructions::filesInMind($agent));
        $kept = $inMind->pluck('id')->flip();

        return [
            'files' => $files->map(fn (AgentSource $s) => $this->summary($s) + ['waiting' => $s->status === AgentSource::READY && ! $kept->has($s->id)])->values()->all(),
            'used' => (int) $inMind->sum(fn ($f) => mb_strlen($f['text'])),
            'budget' => AgentInstructions::MAX_FILES_CHARS,
            'max_files' => self::MAX_FILES,
        ];
    }

    /** @return array<string, mixed> one file as the Knowledge tab shows it */
    private function summary(AgentSource $s): array
    {
        $disk = Storage::disk(AgentSource::DISK);
        $size = $s->path && $disk->exists($s->path) ? (int) $disk->size($s->path) : 0;
        $failed = $s->status === AgentSource::FAILED;

        return [
            'id' => $s->id,
            'name' => (string) $s->title,
            'type' => $s->extension(),
            'sheet' => $s->kind === AgentSource::SHEET,
            'size' => $size,
            'status' => $s->status,
            'partial' => $s->status === AgentSource::READY && $s->error === 'partial',
            'reason' => $failed ? __('agents.files.reasons.'.(in_array($s->error, FileReader::REASONS, true) ? $s->error : 'unreadable')) : null,
            'chars' => (int) $s->chars,
        ];
    }

    private function uploads(Agent $agent)
    {
        return $agent->sources()->whereIn('kind', [AgentSource::FILE, AgentSource::SHEET]);
    }

    private function own(Request $request, Agent $agent): void
    {
        abort_unless((int) $agent->user_id === (int) $request->user()->id, 404);
    }

    private function ownFile(Request $request, Agent $agent, AgentSource $source): void
    {
        $this->own($request, $agent);
        abort_unless((int) $source->agent_id === (int) $agent->id && $source->isUpload(), 404);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Chunked uploads for files dragged from Finder. Browsers don't reveal the
 * on-disk path of a dropped file, so we stream its bytes into our storage
 * and use that copy as the source. Chunks are raw PUT bodies, so PHP's
 * post/upload size limits don't apply.
 */
class UploadController extends Controller
{
    private function tmpPath(string $id): string
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $id), 404);

        return storage_path("app/uploads/tmp/{$id}");
    }

    public function init()
    {
        $id = (string) Str::uuid();
        File::ensureDirectoryExists(storage_path('app/uploads/tmp'));
        touch($this->tmpPath($id));

        return response()->json(['id' => $id]);
    }

    public function chunk(Request $request, string $id)
    {
        $tmp = $this->tmpPath($id);
        abort_unless(is_file($tmp), 404);

        $in = $request->getContent(asResource: true);
        $out = fopen($tmp, 'ab');
        stream_copy_to_stream($in, $out);
        fclose($out);
        clearstatcache(true, $tmp);

        return response()->json(['size' => filesize($tmp)]);
    }

    public function finish(Request $request, string $id)
    {
        $tmp = $this->tmpPath($id);
        abort_unless(is_file($tmp), 404);

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $safe = preg_replace('/[^\w.\- ]/u', '_', basename($data['name']));
        $dest = storage_path('app/uploads/'.now()->format('Ymd-His').'-'.Str::random(6).'-'.$safe);
        File::ensureDirectoryExists(dirname($dest));
        rename($tmp, $dest);

        return response()->json(['path' => $dest]);
    }
}

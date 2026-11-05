<?php

namespace App\Http\Controllers;

use App\Models\WikiEntry;
use Illuminate\Http\Request;

class WikiEntryController extends Controller
{
    public function index(Request $request, string $type)
    {
        $q = WikiEntry::select('id', 'type', 'page_id', 'title', 'slug', 'url', 'description', 'image')->orderBy('title');
        if ($type !== 'all') {
            $q->where('type', $type);
        }
        if ($s = $request->query('search')) {
            $q->where('title', 'like', "%$s%");
        }
        if ($c = $request->query('category')) {
            $q->whereJsonContains('categories', $c);
        }

        return $q->paginate(min((int) $request->query('per_page', 20), 100));
    }

    public function show(string $type, string $slug)
    {
        $q = WikiEntry::where('slug', $slug);
        if ($type !== 'all') {
            $q->where('type', $type);
        }

        return $q->firstOrFail();
    }
}

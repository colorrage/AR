<?php

namespace App\Http\Controllers\Autoresponder;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use CmrManagement\Autoresponder\Models\Campaign;

class CampaignController extends Controller
{
    public function index()
    {
        $campaigns = Campaign::latest()->paginate(10);
        return view('vendor.autoresponder.campaigns.index', compact('campaigns'));
    }

    public function create()
    {
        return view('vendor.autoresponder.campaigns.create');
    }

    public function store(Request $request)
    {
        // Campaign store logic...
        return redirect()->route('autoresponder.campaigns.index');
    }
}

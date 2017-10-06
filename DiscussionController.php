<?php

namespace App\Http\Controllers\Projects;

use App\Models\ProjectDiscussion;
use Illuminate\Http\Request;
use Auth;
use App\Foundation\Http\Controllers\Controller;

class DiscussionController extends Controller
{
    /**
     * Display a listing of the resource.
     * @param $projectId int
     * @return \Illuminate\Http\Response
     */
    public function index($projectId)
    {   

       $projectdiscussions=ProjectDiscussion::where('project_id',$projectId)->latest()->get();
       $url = route('projects.discussion', ['projectId' =>$projectId]);
       $userid=Auth::user()->id;
       return view('projects.discussions.show',compact(['url','projectdiscussions','userid']));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  $projectId int
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request,$projectId)
    {   
         $this->validate($request, [
           'message' => 'required',
         ]);

        $projectdiscussion=new ProjectDiscussion();
        $projectdiscussion->project_id=$projectId;
        $projectdiscussion->message=$request->message;
        $projectdiscussion->author=Auth::user()->id;
        $projectdiscussion->issue=false;
        $projectdiscussion->save();
        return back();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\ProjectDiscussion  $projectDiscussion
     * @return \Illuminate\Http\Response
     */
    public function show(ProjectDiscussion $projectDiscussion)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\ProjectDiscussion  $projectDiscussion
     * @return \Illuminate\Http\Response
     */
    public function edit(ProjectDiscussion $projectDiscussion)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\ProjectDiscussion  $projectDiscussion
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, ProjectDiscussion $projectDiscussion)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\ProjectDiscussion  $projectDiscussion
     * @return \Illuminate\Http\Response
     */
    public function destroy(ProjectDiscussion $projectDiscussion)
    {
        //
    }
}

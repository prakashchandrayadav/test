<?php

namespace App\Http\Controllers\Projects;

use Ramsey\Uuid\Uuid;
use App\Models\Tag;
use App\Models\File;
use App\Models\Language;
use App\Models\Sentence;
use App\Models\Translation;
use App\Models\Project;
use App\Models\UserVerification;
use App\Models\ProjectDeadline;
use Illuminate\Http\Request;
use App\Models\ProjectDiscussion;
use App\Foundation\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Modules\Payments\Earning;
use App\Models\User;
use App\Models\ProjectTranslator;
use App\Http\Controllers\Projects\FileController;
use App\Http\Requests\Client\CreateProjectRequest;
use App\Modules\Payments\Invoice as GenerateInvoice;
use App\Jobs\AssignJobToTranlator;
use App\Jobs\SentenceTranslateUsingService;
use App\Jobs\SentenceTranslateUsingYandexService;
use App\Jobs\SentenceTranslateUsingMicrosoftService;
use App\Modules\Payments\Invoice as InvoiceModule;


use App\Mail\ProjectCreated;
use App\Mail\ProjectPremium;
use App\Mail\Reviewer\ProjectCompleteNotification as ReviewerNotification;
use App\Mail\Reviewer\TranslationCompleteNotification as ReviewerTranslationCompleteNotification;
use App\Mail\Translator\ProjectCompleteNotification as TranslatorNotification;
use App\Mail\Manage\ProjectCompleteNotification as ManagerNotification;
use App\Mail\Manage\TranslationCompleteNotification as ManagerTranslationCompleteNotification;
use App\Mail\Manage\NewProjectForReviewNotification as ManagerNewProjectForReviewNotification;
use App\Mail\Client\ProjectCompleteNotificationFromManager as ManagerProjectCompleteNotification;

use Auth;
use Redirect;
use Mail;
use Session;

class ProjectController extends Controller
{
    public $fileRevisionArray = [];

    /**
     * Show list of all projects
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return redirect()->route('dashboard');

        
        switch (auth()->user()->role) {
            case 'client':
            $projects = Project::where('created_by', \Auth::user()->id)->latest()->get();
            return view('client.projects', compact('projects'));
            break;

            case 'translator':
            $projects = User::find(Auth::user()->id)
                         ->activeProjectsOfTranslator()
                         ->take(3)
                         ->groupBy('project_id')
                         ->latest()
                         ->get();  
            return view('translate.translator.projects', compact('projects'));
            break;

            case 'reviewer':
            $projects =  User::find(Auth::user()->id)
                         ->activeProjectsOfReviewer()
                         ->take(3)
                         ->groupBy('project_id')
                         ->latest()
                         ->get();  
            return view('translate.reviewer.projects', compact('projects'));
            break;

            case 'admin':
            case 'manager':
                $projects = Project::latest()->get();
            return view('manage.projects.index', compact('projects'));
            break;
            // case 'admin':
            //     return view('manage.admin.projects');
            //     break;

            default:
            throw new Exception("Undefined role found!!");
            break;
        }
    }

    /**
     * Show the add project form
     *
     * @return \Illuminate\View\View
     *
     */
    public function create()
    {

        $verificationCount = (int) UserVerification::where('user_id', Auth::user()->id)->count() ;
        if ($verificationCount < 1 || Auth::user()->uservarify->status < 1) {
            return redirect('dashboard')
                ->with('flash_error', 'You need to verify your account before creating a new project.');
        }

        if (Auth::user()->can('create-project')){
         return view('projects.create')
         ->with('tags', Tag::all()->groupBy('group'))
         ->with('languages', Language::all());
     } else {
        return redirect()->route('dashboard');
    }
}

    /**
     * Create a new project
     *
     * @param \App\Http\Requests\Projects\CreateProjectRequest $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(CreateProjectRequest $request)
    {
        $this->authorize('create-project', Auth::user());

        $project = Project::create(
            [
                'name'        => $request->name,
                'code'        => Uuid::uuid1()->toString(),
                'created_by'  => \Auth::user()->id,
                'manager_id'  => $this->assignManager('manager'),
                'premium'  => $premium = $request->input('premium') ? 1 : 0,
                'reviewer'  =>  $request->input('reviewer') == null ? false : true,
                'submited_on' => \Carbon\Carbon::now(),
                'instructions'=> $request->instructions,
            ]
        );
        
        $project->tags()->sync($request->tags);
        $project->languages()->sync($request->languages);

        /*insert rows into project translator for client translator*/
//        $projectLanguages = Project::with('languages')->find($project->id);

        $project->languages->map(function ($language) use($project) {
            $records = ProjectTranslator::where('project_id',$project->id)
                ->where('language_id',$language->id)
                ->orderBy('id','desc')
                ->first();

            if(is_null($records) || $records->assigned == true){
                ProjectTranslator::create([
                    'project_id'   => $project->id,
                    'language_id'  => $language->id,
                    'assigned_by'  => Auth::user()->id,
                    'assigned'     => false,
                ]);
            }
        });
//        foreach($project->languages as $languages){
//
//            $records = ProjectTranslator::where('project_id',$project->id)
//            ->where('language_id',$languages->id)
//            ->orderBy('id','desc')
//            ->first();
//
//            if(is_null($records) || $records->assigned == true){
//                ProjectTranslator::create([
//                'project_id'   => $project->id,
//                'language_id'  => $languages->id,
//                'assigned_by'  => Auth::user()->id,
//                'assigned'     => false,
//                ]);
//            }
//        }
        /*enddd*/

        Mail::to(Auth::user())->send(new ProjectCreated($project));
        session(['first' => 1]);
        return redirect()
        ->route('projects.show', $project->code)
        ->with('flash_success', 'Project created successfully!');
    }

    /**
     * Show a sepecific project details
     *
     * @param integer $code Project Code
     *
     * @return \Illuminate\Http\Response
     */
    public function show($code)
    {

        $user = Auth::user();


        $project  = Project::with([
            'tags',
            'transactions',
            'languages',
            'translations',
            'files',
            'sentences'
            ])->where('code', $code)->first();

        if($user->id != $project->client->id && $user->role == 'client'){
            return redirect()->route('dashboard');
        }

        if (count($project->files->where('format',null))) {
            $corruptFiles = $project->files->where('format',null)->first();
            $fileObj = new FileController();
            $fileObj->deleteFile($corruptFiles->id);
        }


        $project->languages->transform(function($language) use($project, $user) {

            if(!Auth::user()->client)
            {

                $totalSentences = $project->sentences()->paidSentence()->where('status','!=',2)
                                  ->pluck('id');
                $translations = Translation::where('language_id', $language->id)
                                ->whereIn('sentence_id',$totalSentences)
                                ->where('status','!=',4);
            }else
            {
                $totalSentences = $project->sentences()->where('status','!=',2)
                                  ->pluck('id');
                $translations = Translation::where('language_id', $language->id)
                                    ->whereIn('sentence_id',$totalSentences)
                                    ->where('status','!=',4);

            }
            

            $language->totalCount = $translations->count();

            $language->completedCount = Translation::where('language_id', $language->id)
                                    ->whereIn('sentence_id',$totalSentences)
                                    ->where('status',3)
                                    ->where('status','!=',4)->count();
            

            if (!empty($language->completedCount) && !empty($language->totalCount)) {
                $language->progress = round(($language->completedCount / $language->totalCount)*100, 0);
            } else {
                $language->progress = 0;
            }


                if($language->totalCount > 0){

                    $pendingTranslationCount = Translation::where('language_id', $language->id)
                                    ->whereIn('sentence_id',$totalSentences)
                                    ->where('status','!=',4)
                                    ->whereIn('status',[0,2])
                                    ->count();
                    $language->pendingPercent = round(($pendingTranslationCount / $language->totalCount)*100,0);

                }else{
                    $language->pendingPercent = 0;
                }

                if($language->totalCount > 0){
                    $translatedTranslationCount = Translation::where('language_id', $language->id)
                                    ->whereIn('sentence_id',$totalSentences)
                                    ->where('status','!=',4)
                                    ->where('status',1)
                                    ->count();
                    $language->translatedPercent = round(($translatedTranslationCount / $language->totalCount)*100,0);
                }else{
                    $language->translatedPercent = 0;
                }


                if($language->totalCount > 0){

                    $acceptedTranslationCount = Translation::where('language_id', $language->id)
                                    ->whereIn('sentence_id',$totalSentences)
                                    ->where('status','!=',4)
                                    ->where('status',3)
                                    ->count();

                    $language->acceptedPercent = round(($acceptedTranslationCount / $language->totalCount)*100,0);
                }else{
                    $language->reviewedPercent = 0;
                }




            $language->translators = $project->translators()
                ->where('project_translators.language_id',$language->id)
                ->orderBy('project_translators.id','desc')
                ->first();

            return $language;
        });


        if(in_array(Auth::user()->role,['translator','reviewer'])){
        $tempFileId = $project->sentences()->paid()->with('file')->get()->pluck('file.id');
        $filesToBeAllowedForEditing = File::whereIn('id',$tempFileId)->get();
        $project->files = $filesToBeAllowedForEditing;
        }


        // we need to combine file data if it have previous id
        


        $project->files->transform(function($file) use($project, $user)
        {

            $sentences = $project->sentences->where('file_code', $file->code)->pluck('id');

            $translations = $project->translations->whereIn('sentence_id', $sentences);

            if($user->role == 'translator') {
                $translations = $translations->where('translator_id', $user->role);
            }

            if($user->role == 'reviewer') {
                $translations = $translations->where('translator_id', $user->role);
            }
            $file->totalCount = $file->translations
            ->where('status','!=',4)
            ->count();
            //dd($file->id);

            
                /*$array = array_values(array_sort($file->fileRevision, function ($value) {
                    return $value['id'];
                }));*/
               // dd($array);
                /*
                if(!is_null($file->fileRevision))
                {
                    foreach ($file->fileRevision as $key => $value) {
                            print_r($value[]) ;
                        }    exit;
                }
                dd($file->fileRevision);*/

                //if ($user->role != "translator") {

                    $file->completedCount = $file->translations->where('status', 3)->count();
                /*} else {

                    $file->completedCount = $file->translations->where('translations', '!=', NULL)->count();
                }*/

                if (!empty($file->completedCount) && !empty($file->totalCount)) {
                    $file->progress = round(($file->completedCount / $file->totalCount)*100, 0);
                } else {
                  $file->progress = 0;
                }


                if ($file->translations->count() > 0 && $file->totalCount > 0) {
                    $file->pendingPercent = round(($file->translations->whereIn('status', [0, 2])
                         ->count()/ $file->totalCount)*100,0);
                } else {
                    $file->pendingPercent = 0;
                }

                if (!empty($file->translations) && $file->totalCount > 0) {
                    $file->translatedPercent = round(($file->translations->where('status', 1)
                         ->count()/ $file->totalCount)*100, 0);
                } else {
                    $file->translatedPercent = 0;
                }

                if (!empty($file->translations) && $file->totalCount > 0) {
                    $file->acceptedPercent = round(($file->translations->where('status', 3)
                         ->count()/ $file->totalCount)*100, 0);
                } else {
                    $file->reviewedPercent = 0;
                }




                /*['Pending', @if($project->translations->whereIn('status',[0, 2])->count()){{$project->translations->where('status',0)->count()}} @else 1 @endif],
                ['Reviewed',  {{$project->translations->whereIn('status', [2, 3])->count()}}],
                ['Translated',      {{$project->translations->where('status',1)->count()}}],
                ]*/

                return $file;
            });


        $projectdiscussions=ProjectDiscussion::where('project_id',$project->id)->latest()->get();

        $invoices=Invoice::where('project_id',$project->id)->latest()->get();

        // TODO: Remove this....
        $userid=Auth::user()->id;
        //dd($project->translations);
        $projectStatusButton = $this->projectStatus($project->id, $project->translations);
        // dd($project->files);
        /*counting total project progress to be displayed on show page*/
         if(!Auth::user()->client)
            {

                $totalSentences = $project->sentences()->paidSentence()->where('status','!=',2)
                                  ->pluck('id');
                $totalTranslations = Translation::whereIn('sentence_id',$totalSentences)
                                     ->where('status','!=',4)->count();
                $total = $totalTranslations == 0 ? 1 : $totalTranslations;

                $reviewed = Translation::whereIn('sentence_id',$totalSentences)->whereIn('status',[2, 3])->count();
                $translated = Translation::whereIn('sentence_id',$totalSentences)
                                     ->where('status',1)->count();
                $pending = Translation::whereIn('sentence_id',$totalSentences)
                                     ->whereIn('status',[0,2])->count();




            }else
            {
                $totalSentences = $project->sentences()->where('status','!=',2)
                                  ->pluck('id');
                $totalTranslations = Translation::whereIn('sentence_id',$totalSentences)
                                     ->where('status','!=',4)->count();
                $total = $totalTranslations == 0 ? 1 : $totalTranslations;

                $reviewed = Translation::whereIn('sentence_id',$totalSentences)
                                     ->whereIn('status', [2, 3])->count();
                $translated = Translation::whereIn('sentence_id',$totalSentences)
                                     ->where('status',1)->count();
                $pending = Translation::whereIn('sentence_id',$totalSentences)
                                     ->whereIn('status',[0,2])->count();
            }



        /*counting ends here*/
        return view('projects.show', compact('project', 'user', 'projectdiscussions', 'id','userid', 'invoices', 'projectStatusButton','total','reviewed','translated','pending'));
    }

    /**
    * Check Completed button status
    *
    * @param $projectId
    * @param $translation
    *
    * @return Illuminate\Http\Response
    */

    protected function projectStatus($projectId, $translation)
    {
        $role           = Auth::user()->role;
        $projectInfo    = Project::find($projectId);
        //If project is premium
        if($projectInfo->premium)
        {
            switch ($role) {
                case 'client':
                    if($projectInfo->status == 5)
                    {
                        return true;
                    }
                    break;

                case 'translator':

                    if(($projectInfo->status == 3)||($projectInfo->status == 7))
                    {
                        $data = $translation->pluck('status')->toArray();
                        if(in_array("0", $data) || in_array("2", $data))
                            {
                                return false;
                            }
                        return true;
                    }
                    break;

                case 'reviewer':
                     if($projectInfo->status==10)
                     {
                        $data = $translation->pluck('status')->toArray();
                        if(in_array("0", $data) || in_array("2", $data))
                            {
                                return false;
                            }
                        return true;
                     }
                    break;

                case 'manager':
                case 'admin':
                    if($projectInfo->status == 4)
                    {
                        return true;
                    }
                    break;

                default:
                    return false;
                    break;

            }
                return false;

        }
        else
            {
                if(Auth::user()->role == "client")
                {
                    if($projectInfo->status == 5)
                    {
                        return true;
                    }
                }elseif(Auth::user()->role == "translator")
                {
                    if(($projectInfo->status == 3)||($projectInfo->status == 7))
                    {
                        $data = $translation->pluck('status')->toArray();
                        if(in_array("0", $data) || in_array("2", $data))
                            {
                                return false;
                            }
                        return true;
                    }
                }
            }
            return false;

    }
    /**
     * Show a list of languages for given project
     *
     * Lists all the languages for given project with current
     * translation status and progress
     *
     * @param integer $id Project ID
     *
     * @return \Illuminate\Http\Response
     **/
    public function languages($id)
    {
        $project = Project::find($id);

        return view('projects.lanuages.index')
        ->with('project', $project)
        ->with('languages', $project->languages);
    }

    /**
     * Add a new language to existing project
     *
     * Adds additional language to existing project for
     * additional translations
     *
     * @param \Illuminate\Http\Request  $request
     * @param integer $id Project ID
     *
     * @return \Illuminate\Http\Response
     **/
    public function storeLanguage(Request $request, $id)
    {
        $this->validate($request, [
               'languages' => 'required',
        ]);
        $languages = $request->get('languages');
        $project = Project::find($id);
        $project->languages()->attach($request->get('languages'));

        //$unPaidFileCodes = Sentence::where('paid',false)->where('project_id',$project->id)->groupBy('file_code')->get();  // projects


        foreach ($project->files as $key => $file) {
            
        
        /* making all entries related to project for added language*/
         foreach ($project->languages as $language) {

            if(in_array($language->id, $request->get('languages')))
            {

                dispatch(new AssignJobToTranlator($project->id, $language->id, null, $file->code));
                
                 if (collect(project('automatedSources.google'))->has($language->code)) {
                    /*translate using google service*/
                    dispatch(new SentenceTranslateUsingService($file->code, $language->id));
                }
                 if (collect(project('automatedSources.yandex'))->has($language->code)) {
                    /*translate using yandex service*/
                    dispatch(new SentenceTranslateUsingYandexService($file->code, $language->id));
                }
                if (collect(project('automatedSources.microsoft'))->has($language->code)) {
                    /*translate using microsoft service*/
                    dispatch(new SentenceTranslateUsingMicrosoftService($file->code, $language->id));
                }

                }
            }

            }

        //genrating invoices

        //$unPaidProjects = Sentence::where('paid',false)->groupBy('project_id')->get(); 

        $invoiceObject = new InvoiceModule;
        $languages = $request->get('languages');
        $invoiceObject->generateForLanguage($project->id,$languages);// // randome file id since it is not 
        

        

       /* Invoice::where('project_id',$project->id)
        ->where('user_id',Auth::user()->id)
        ->where('paid',false)
        ->where('total','<',1)
        ->delete();*/
        //dd('added language');
        // TODO: redirect somewhere....
         return redirect()->back()->with('flash_success', 'Language added successfully');;
    }

    

    /**
     * Make the project premium
     * @param \Illuminate\Http\Request  $request
     * @param integer Project ID
     *
     * @return \Illuminate\Http\Response
     **/
    public function makeProjectPremium($projectId,$proofReader)
    {
        $project = Project::find($projectId);
        $project->premium = True;
        $project->reviewer = $proofReader;
        $project->save();
        foreach ($project->languages as $language) {
            ProjectTranslator::where('project_id', $project->id)
              ->where('language_id', $language->id)
              ->orderBy('id', 'DESC')
              ->update([
                    'translator_id' => null,
                    'assigned'  =>  false
              ]);
            # code...
        }

        (new GenerateInvoice)->generate($projectId,null);
        Mail::to(Auth::user())->send(new ProjectPremium($project));
        return Redirect::back();
    }

    /**
    * Assign random generated manger to a project
    * @param $role
    * @return Illuminate/Http/Response
    */
    function assignManager($role)
    {
        $managers = User::where('role',$role)->with(['projects' => function($query){
            $query->select('manager_id', \DB::raw("count(id) as count"))->groupBy('manager_id');
        }])->get()->toArray();
        $first=$second=10000;
        foreach ($managers as $key => $manager)
        {
            foreach ($manager as $key2 => $value) {
                # code...
                if($key2=='projects')
                {
                    if(empty($value))
                    {
                        return $manager['id'];
                    }
                    else
                    {
                        $managerId = $value[0]['count'];
                        if ($value[0]['count'] <= $first)
                        {
                            $second = $first;
                            $first = $value[0]['count'];
                            $managerId = $manager['id'];
                        }
                    }
                }
            }
        }

        return $managerId;
    }

    /**
      *  Translation completed
      *
      * @param $projectId
      *
      * @return Illuminate/Http/Response
      */
     public function translationCompleted($projectId)
     {

        $role           = Auth::user()->role;
        $projectInfo    = Project::find($projectId);

        //If project is premium
        if($projectInfo->premium)
        {
            switch ($role) {
                case 'client':
                    $projectInfo->status = 9;
                    $projectInfo->save();
                    $earning = new Earning();
                    $earning->calculate($projectId);
                    if($projectInfo->reviewer)
                    {
                        foreach($projectInfo->reviewers as $reviewer)
                            {
                            Mail::to($reviewer->email)->send(new ReviewerNotification($reviewer, $projectInfo));
                            }

                    }

                        foreach($projectInfo->translators as $translator)
                            {
                                Mail::to($translator->email)->send(new TranslatorNotification($translator, $projectInfo));
                            }



                    Mail::to($projectInfo->manager)->send(new ManagerNotification($projectInfo->manager, $projectInfo));

                    break;

                case 'translator':


                    if($projectInfo->reviewer){
                        $projectInfo->status = 10;
                      foreach($projectInfo->reviewers as $reviewer)
                        {
                            Mail::to($reviewer->email)->send(new ReviewerTranslationCompleteNotification($reviewer, $projectInfo));
                        }

                    }else
                    {
                        $projectInfo->status = 4;
                         Mail::to($projectInfo->manager)->send(new ManagerTranslationCompleteNotification($projectInfo->manager, $projectInfo));
                    }

                    break;

                case 'reviewer':
                    $projectInfo->status = 4;
                     Mail::to($projectInfo->manager)->send(new ManagerNewProjectForReviewNotification($projectInfo->manager, $projectInfo));
                    break;

                case 'manager':
                case 'admin':
                    $projectInfo->status = 5;
                    Mail::to($projectInfo->client)->send(new ManagerProjectCompleteNotification($projectInfo->manager, $projectInfo));
                    break;

                default:
                    return redirect()->back()->with('flash_error', 'You are not authorized to perform this action');
                    break;

            }
              $projectInfo->save();
              return redirect()->back()->with('flash_success', 'Project marked completed successfully!');
        }
        else
        {
            if($role == "client")
            {
                $projectInfo->status = 9;
                $projectInfo->save();

                foreach($projectInfo->translators as $translator)
                {
                    Mail::to($translator->email)->send(new TranslatorNotification($translator, $projectInfo));
                }
            }
            else
            {
                // If project is not premium
                $projectInfo->status = 5;
                $projectInfo->save();
                Mail::to($projectInfo->client)->send(new ManagerProjectCompleteNotification($projectInfo->manager, $projectInfo));
            }
            return redirect()->back()->with('flash_success', 'File is marked as completed by you');
        }

    }



}

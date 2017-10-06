<?php

namespace App\Http\Controllers\ExternalApi;

use App\Foundation\Http\Controllers\ExternalApiController;
//use App\Jobs\GenerateInvoice;
use Auth;
use Carbon\Carbon;
use App\Models\File;
use Ramsey\Uuid\Uuid;
use File as InputFile;
use App\Models\Project;
use App\Models\Sentence;
use App\Models\Translation;

use App\Models\Invoice;
use App\Models\Language;
use App\Models\InvoiceItem;
use App\Models\ProjectLanguage;
use App\Models\ProjectTranslator;

use App\Jobs\AssignJobToTranlator;
use App\Jobs\SentenceTranslateUsingService;
use App\Jobs\SentenceTranslateUsingYandexService;
use App\Jobs\SentenceTranslateUsingMicrosoftService;
use App\Modules\Payments\Invoice as GenerateInvoice;

use App\Http\Requests\Project\AddFileRequest;
use App\Http\Requests\Project\UpdateFileRequest;
use App\Http\Requests\Project\DownloadFileRequest;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Modules\Files\Parser\ParserFactory;
use Illuminate\Support\Facades\Input;
use Illuminate\Http\Response;
class FileController extends ExternalApiController
{
    public $fileRevisionArray = [];
    /**
     *  project single file and multiple file upload  
     *  
   	 *	@param $projectId string
   	 *	@param $request string
     *  @return json object
     */
    public function uplaodeFiles($projectCode , Request $request)
    {
    	/* find project by project code*/
        $project = Project::where('code',$projectCode)->first();
         

         /*check condition */ 
        if($project!=null && $request->file('file')!='' && $request->format!='')
        {
            /* check file */
	        if(is_array($request->file('file'))){
                /*looping file here*/
	        	foreach ($request->file('file') as $singlefile) {
	        	
	            $format=false;
			        /*get file extension*/
			        $extension = $singlefile->getClientOriginalExtension();
			            /*check file type*/
			        foreach(project('file.types') as $key => $value){

			            if($request->format==$key){
			                $format = true;   
			            }
			        }
		            /*check file extension and request format*/
			        if($format)
			        {   
			        	/*file store*/
				        $filePath = Storage::putFileAs('projects/'. $projectCode, $singlefile,  Uuid::uuid1()->toString() . '.' . $extension);
				        /*file save in databas*/
				        $file=new File();
    						$file->project_id=$project->id;
    						$file->code=Uuid::uuid1()->toString();
    						$file->name=$singlefile->getClientOriginalName();
    						$file->location=$filePath;
    						$file->type=$extension;
    						$file->format=$request->format;
    						$file->created_at=\Carbon\Carbon::now();
    						$file->save();
				        /*assignjob here*/
				        $this->AssignJob($project,$file);
                        /*data*/
			            $data[]=[ 'file_id'=>$file->id,
							     'success'=>$file->name.' is uploaded',
							     'dir'    =>$destinationPath=storage_path('app/projects/'.$file->code)
						       ];
			        }
			        else
			        {
			            /*data*/
		              $data[]=[
    						    'Error'=>'file format does not match'
    					      ];
				      }
				    }

				    $status=202;  

	        }
	        else
	        {
		        $format=false;
		        /*get file extension*/
		        $extension = $request->file('file')->getClientOriginalExtension();
		            /*check file type*/
		        foreach(project('file.types') as $key => $filetype){

		            if(array_key_exists($request->format,$filetype)){
		                $format = true;
		            }
		        }
	            /*check file extension and request format*/
		        if($format)
		        {   
		        	/*file store*/
			        $filePath = Storage::putFileAs('projects/'. $projectCode, $request->file('file'),  Uuid::uuid1()->toString() . '.' . $extension);
			        /*file save in databas*/
			        $file=new File();
    					$file->project_id=$project->id;
    					$file->code=Uuid::uuid1()->toString();
    					$file->name=$request->file('file')->getClientOriginalName();
    					$file->location=$filePath;
    					$file->type=$extension;
    					$file->format=$request->format;
    					$file->created_at=\Carbon\Carbon::now();
    					$file->save();
			          /*assignjob here*/
			          $this->AssignJob($project,$file);
                      /*data*/
			           $data=[ 'file_id'=>$file->id,
                          'file_code'=>$file->code,
							           'success'=>$file->name.' is uploaded',
							           'dir'    =>$destinationPath=storage_path('app/projects/'.$file->code)
						     ];
						/*status*/
			            $status=200;
		        }
		        else
		        {
		           /*data*/
	               $data=[
      					    'Error'=>'file format does not match'
      				      ];
				      /*status*/
					     $status=404;  
		        }  	
		    }

        }else{
            /*data*/
      			$data=[
      				    'Error'=>'Something is wrong'
      			      ];
      			/*status*/
      			$status=404;
	    }    
        return $this->apiResponce($data,$status); 
    }

    /**
     *  all jobs run here and auto translate santances by google,yandex,and microsoft 
     *	@param $project 
   	 *	@param $file 
     *  @return boolen 
     */
    public function AssignJob($project,$file)
    {
    	//parser factory
        $obj = new ParserFactory($file);
        $result = $obj->type();
       
        foreach ($project->languages as $language) {

            dispatch(new AssignJobToTranlator($project->id, $language->id, null, $file->code));
            
            if (collect(project('automatedSources.google'))->has($language->code)) 
            {
               /*translate using google service*/
               dispatch(new SentenceTranslateUsingService($file->code, $language->id));
            }
            if (collect(project('automatedSources.yandex'))->has($language->code)) 
            {
               /*translate using yandex service*/
                dispatch(new SentenceTranslateUsingYandexService($file->code, $language->id));
            }
            if (collect(project('automatedSources.microsoft'))->has($language->code)) 
            {
               /*translate using microsoft service*/
                dispatch(new SentenceTranslateUsingMicrosoftService($file->code,  $language->id));
            }
            
            /**
             * check project is premium 
             */
            if ($project->premium == false) {
                 $records = ProjectTranslator::where('project_id',$project->id)
                  ->where('language_id',$language->id)
                  ->orderBy('id','desc')
                  ->first();

                  if (is_null($records) || $records->assigned == true) {
                      ProjectTranslator::create([
                              'project_id'   => $project->id,
                              'language_id'  => $language->id,
                              'assigned_by'  => $project->created_by,
                              'assigned'     => false,
                          ]);  
                  }
            }
        }
        /* if ($project->premium == true) {
            (new GenerateInvoice)->generate($project->id,$file);
        }*/
        return true;
    }
     /**
     * files format
     *
     * @return \Illuminate\Http\Response
    */
    public function getFilesFormats()
    {
        foreach (config('project.file.types') as $key => $value) {
        	$data[]=$key;
        }
        
        $status=200;

        return $this->apiResponce($data,$status);
    }

    /**
    *  Download file here
    *  @param $languageCode
    *  @return \Illuminate\Http\Response
    */
    public function downloadFile($fileCode,$languageCode){

    	  $File=File::where('code',$fileCode)->first();

          $destinationPath=storage_path('app/projects/'.$fileCode);
          
        return response()->download($destinationPath.'/'.$fileCode.'_'.$languageCode.'.'.$File->type);
    }
    /**
    * get project file Download url 
    * @param string $projectCode
    * @param init $fileId
    * @return \Illuminate\Http\Response
    */
    public function getSingleFileDownloadUrls(Request $request,$projectCode,$fileId=0)
    {    
    	  /* find project by project code*/
        $project = Project::where('code',$projectCode)->first();
        /* get file */
    	  $file = File::find($fileId);
    	  /* file parsing here*/
        $obj = new ParserFactory($file);
        $data=[];
        /* check language set in request*/
        if($request->languages!=null && $request->languages!=''){
            /* find language*/
            $project_lang=$project->languages()->where('code',$request->languages)->first();
             /* check language*/
            if($project_lang!=null){
                /* get dir*/
             	$dirfile=$obj->download($project_lang->id,true)['file_code'];
             	/* data */
            	$data=url('project/download/files/'.$dirfile.'/'.$project_lang->code);

            }else{
             	/* data */
             	$data=['error'=>'Something is wrong'];
            }
        }else{
        	 /* language not set than get all language files*/
             if($project->languages!=null){
               
                foreach ($project->languages as $project_lang) {
                    /* dir*/
	             	$dirfile=$obj->download($project_lang->id,true)['file_code'];
	             	/* data*/
                	$data[]=url('project/download/files/'.$dirfile.'/'.$project_lang->code);
            	}
            }
        }
        
        return $this->apiResponce($data,200);
    }
    
    /**
    *  gete Download url of project files
    *  @param $projectCode
    *  @return \Illuminate\Http\Response
    */
    public function getFilesDownloadUrls(Request $request,$projectCode)
    {       
    	/* find project by project code*/
		$project = Project::where('code',$projectCode)->first();
	    //$project_lang=$project->languages->pluck('code','id')->toArray();
        foreach ($project->files as $file)
        { 
        	/* file parsing here*/
        	$obj = new ParserFactory($file);
        	$data[$file->id]=[];
        	foreach ($project->languages as $project_lang) {
                     /* check responce*/
                    if($obj->download($project_lang->id,true)){
                       /* dir */
	             	    $dirfile=$obj->download($project_lang->id,true)['file_code'];
                	    $data[$file->id][]=url('project/download/files/'.$dirfile.'/'.$project_lang->code);
                    }
              }
    	}

        return $this->apiResponce($data,202);   
    }
    /**
    * Delete file here and make status 2
    * @param $projectId
    * @param $fileId
    * @return \Illuminate\Http\Response
    */
   public function deleteFileAndSentences($projectCode, $fileId)
   {    /* get file */
		$fileToBeDeleted = File::find($fileId);
        /* check file */
        if($fileToBeDeleted!=null)
        {  
            	/* update sentence*/
    			$sentenceStatus = Sentence::where('file_code',$fileToBeDeleted->code)->update(['status'=>'2']);
                /*get sentences ids of array*/
    			$sentencesArrayForDeletion = $fileToBeDeleted->sentences->pluck('id');
    	        /* set translattion status 4*/
    			$updateStatus = Translation::whereIn('sentence_id',$sentencesArrayForDeletion)->update(['status'=>'4']);
                /* file delete here */
    			$deleteStatus = File::where('id', $fileId)->delete();
                 /* success responce here*/
    	        $data=[ 
    				    'success'=>$fileToBeDeleted->name.' is deleted',
    			      ];
                /*status*/
                $status=200;
    	    }else{
    			$data=[
    				    'Error'=>'Something is wrong'
    			      ];
    			/*status*/
    			$status=404;
    	    }
		return $this->apiResponce($data,$status);
    }

    /**
     * Display the specified resource.
     * @param  int  $fileId
     * @return \Illuminate\Http\Response
     */
    public function getFilesStatus($projectCode,$fileId=0)
    {   
    	/* find project by project code*/

        $project = Project::where('code',$projectCode)->first();

        if($project!=null)
        {   
    	    if($fileId==0){
                /* files status */
	            foreach ($project->files as $file) {
                    $data[]=$this->getTranslationsStatus($file);
	        	}
                /*status*/
	        	$status=200;
    	    }
    	    else
    	    {
                /* single file status*/
        		$file = $project->files()->find($fileId);
                 
        		if($file!=null){
                    /* get tanslation status */
                	$data=$this->getTranslationsStatus($file);
                    /*status*/
			        $status=200;
        		}else{
                     /*data*/
					$data=[
						    'Error'=>'Something is wrong'
					      ];
					/*status*/
					$status=404;
        		}
        	}	
        }
        else
        {   /*error*/
            $data=[
                    'Error'=>'Something is wrong'
                  ];
            /*status*/
            $status=404;
        }
        /*responce*/
        return $this->apiResponce($data,$status);
    }

    /**
     * getTranslationsStatus.
     *
     * @param  type  $file
     * @return array
     */
    function getTranslationsStatus($file)
    {
    	
		    $pending=0;
		    $reviewed=0;
		    $translated=0;
     	  //pending translations
        $totale=$file->translations->count();
        //pending translations
        if($file->translations->where('status',0)->count()>0)
         $pending=($file->translations->where('status',0)->count()/$totale)*100;

        //reviewe
        if($file->translations->whereIn('status',[2, 3])->count()>0)
        $reviewed=($file->translations->whereIn('status',[2, 3])->count()/$totale)*100;

        //translated
        if($file->translations->where('status',1)->count()>0)
        $translated=($file->translations->where('status',1)->count()/$totale)*100;
        
        //data 
        $data=[
                "file_name" => $file->name,
                'file_id'=>$file->id, 
                'file_status' =>[

                                'status' =>config('project.file.status')[$file->status],
                                'totale_translation'=>$totale,
                                'Pending' => $pending.'%',
                                'Reviewed' =>$reviewed.'%',
                                'Translated' =>$translated.'%',
                        ],
            ];

        return $data;
    }
}

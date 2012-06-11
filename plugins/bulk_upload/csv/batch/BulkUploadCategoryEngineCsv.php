<?php
/**
 * Class which parses the bulk upload CSV and creates the objects listed in it. 
 * This engine class parses CSVs which describe categories.
 * 
 * @package plugins.bulkUploadCsv
 * @subpackage batch
 */
class BulkUploadCategoryEngineCsv extends BulkUploadEngineCsv
{
    
    protected $mapFullNameToId = array();
    
    /**
     * (non-PHPdoc)
     * @see BulkUploadGeneralEngineCsv::createUploadResult()
     */
    protected function createUploadResult($values, $columns)
	{
		if($this->handledRecordsThisRun > $this->maxRecordsEachRun)
		{
			$this->exceededMaxRecordsEachRun = true;
			return;
		}
		$this->handledRecordsThisRun++;
		
		$bulkUploadResult = new KalturaBulkUploadResultCategory();
		$bulkUploadResult->bulkUploadResultObjectType = KalturaBulkUploadResultObjectType::CATEGORY;
		$bulkUploadResult->bulkUploadJobId = $this->job->id;
		$bulkUploadResult->lineIndex = $this->lineNumber;
		$bulkUploadResult->partnerId = $this->job->partnerId;
		$bulkUploadResult->rowData = join(',', $values);
			 
				
		// trim the values
		array_walk($values, array('BulkUploadCategoryEngineCsv', 'trimArray'));
		
		// sets the result values
		foreach($columns as $index => $column)
		{
			if(!is_numeric($index))
				continue;
            
			if ($column == 'categoryId')
			{
			    $bulkUploadResult->objectId = $values[$index];
			}
				
			if(iconv_strlen($values[$index], 'UTF-8'))
			{
				$bulkUploadResult->$column = $values[$index];
				KalturaLog::info("Set value $column [{$bulkUploadResult->$column}]");
			}
			else
			{
				KalturaLog::info("Value $column is empty");
			}
		}
		
		if(isset($columns['plugins']))
		{
			$bulkUploadPlugins = array();
			
			foreach($columns['plugins'] as $index => $column)
			{
				$bulkUploadPlugin = new KalturaBulkUploadPluginData();
				$bulkUploadPlugin->field = $column;
				$bulkUploadPlugin->value = iconv_strlen($values[$index], 'UTF-8') ? $values[$index] : null;
				$bulkUploadPlugins[] = $bulkUploadPlugin;
				
				KalturaLog::info("Set plugin value $column [{$bulkUploadPlugin->value}]");
			}
			
			$bulkUploadResult->pluginsData = $bulkUploadPlugins;
		}
		
		$bulkUploadResult->objectStatus = KalturaCategoryStatus::ACTIVE;
		$bulkUploadResult->status = KalturaBulkUploadResultStatus::IN_PROGRESS;
		
		if (!$bulkUploadResult->action)
		{
		    $bulkUploadResult->action = KalturaBulkUploadAction::ADD;
		}
		
		$bulkUploadResult = $this->validateBulkUploadResult($bulkUploadResult);
		
		$this->bulkUploadResults[] = $bulkUploadResult;
	}
    
	protected function validateBulkUploadResult (KalturaBulkUploadResult $bulkUploadResult)
	{
	    if ($bulkUploadResult->action == KalturaBulkUploadAction::ADD_OR_UPDATE)
		{
		    if ( $bulkUploadResult->objectId || $bulkUploadResult->referenceId)
		    {
		        $this->impersonate();
		        $bulkUploadResult->objectId = $this->calculateIdToUpdate($bulkUploadResult);
		        $this->unimpersonate();
		        if ($bulkUploadResult->objectId)
		        {
		            $bulkUploadResult->action = KalturaBulkUploadAction::UPDATE;
		        }
		        else
		        {
		            $bulkUploadResult->action = KalturaBulkUploadAction::ADD;
		        }
		    }
		    else 
		    {
		        $bulkUploadResult->action = KalturaBulkUploadAction::ADD;
		    }
		}
		
		switch ($bulkUploadResult->action)
		{
		    case KalturaBulkUploadAction::ADD:
        		if( !$bulkUploadResult->name )
        		{
        			$bulkUploadResult->status = KalturaBulkUploadResultStatus::ERROR;
        			$bulkUploadResult->errorType = KalturaBatchJobErrorTypes::APP;
        			$bulkUploadResult->errorDescription = "Mandatory Column [name] missing from CSV.";
        		}
        			
		        break;
		       
		    case KalturaBulkUploadAction::UPDATE:
        		if (!$bulkUploadResult->objectId && !$bulkUploadResult->referenceId)
    		    {
    		        $bulkUploadResult->status = KalturaBulkUploadResultStatus::ERROR;
    			    $bulkUploadResult->errorType = KalturaBatchJobErrorTypes::APP;
    			    $bulkUploadResult->errorDescription = "Mandatory parameters missing for action [".$bulkUploadResult->action ."] - categoryId/referenceId";
    		    }
		        break;
		    
		    case KalturaBulkUploadAction::DELETE:
		        if (!$bulkUploadResult->objectId && !$bulkUploadResult->referenceId)
    		    {
    		        $bulkUploadResult->status = KalturaBulkUploadResultStatus::ERROR;
    			    $bulkUploadResult->errorType = KalturaBatchJobErrorTypes::APP;
    			    $bulkUploadResult->errorDescription = "Mandatory parameters missing for action [".$bulkUploadResult->action ."]";
    		    }
		        break;
		}
		

		if($this->maxRecords && $this->lineNumber > $this->maxRecords) // check max records
		{
			$bulkUploadResult->status = KalturaBulkUploadResultStatus::ERROR;
			$bulkUploadResult->errorType = KalturaBatchJobErrorTypes::APP;
			$bulkUploadResult->errorDescription = "Exeeded max records count per bulk";
		}
		
		if($bulkUploadResult->status == KalturaBulkUploadResultStatus::ERROR)
		{
			$this->addBulkUploadResult($bulkUploadResult);
			return;
		}	
		
		return $bulkUploadResult;
	}
	
	
    protected function addBulkUploadResult(KalturaBulkUploadResult $bulkUploadResult)
	{
		parent::addBulkUploadResult($bulkUploadResult);
		
	}
	/**
	 * 
	 * Create the entries from the given bulk upload results
	 */
	protected function createObjects()
	{
		// Because the bulk upload feature may be used to construct a category tree, we are unable to work with an ordinary multi-request.
		$requestResults = array();
		KalturaLog::info("job[{$this->job->id}] start creating categories");
		$bulkUploadResultChunk = array(); // store the results of the created entries
				
		
		$this->impersonate();
		foreach($this->bulkUploadResults as $bulkUploadResult)
		{
			/* @var $bulkUploadResult KalturaBulkUploadResultCategory */
		    KalturaLog::debug("Handling bulk upload result: [". $bulkUploadResult->name ."]");
		    try 
		    {
    		    switch ($bulkUploadResult->action)
    		    {
    		        case KalturaBulkUploadAction::ADD:
        		        $category = $this->createCategoryFromResultAndJobData($bulkUploadResult);
            			$bulkUploadResultChunk[] = $bulkUploadResult;
                		$requestResults[] = $this->kClient->category->add($category);
 
    		            break;
    		        
    		        case KalturaBulkUploadAction::UPDATE:
    		            $bulkUploadResult->objectId = $this->calculateIdToUpdate($bulkUploadResult);
    		            if (is_null($bulkUploadResult->objectId))
    		            {
    		                $bulkUploadResult->status = KalturaBulkUploadResultStatus::ERROR;
    		                $bulkUploadResult->errorDescription = "Category reference ID not found";
    		            }
    		            $category = $this->createCategoryFromResultAndJobData($bulkUploadResult);
            			$bulkUploadResultChunk[] = $bulkUploadResult;
                		$requestResults[] = $this->kClient->category->update($bulkUploadResult->objectId, $category);
    		            break;
    		            
    		        case KalturaBulkUploadAction::DELETE:
    		            $bulkUploadResult->objectId = $this->calculateIdToUpdate($bulkUploadResult);
    		            if (is_null($bulkUploadResult->objectId))
    		            {
    		                 $bulkUploadResult->status = KalturaBulkUploadResultStatus::ERROR;
    		                $bulkUploadResult->errorDescription = "Category reference ID not found";
    		            }
    		            $bulkUploadResultChunk[] = $bulkUploadResult;
                		$requestResults[] = $this->kClient->category->delete($bulkUploadResult->objectId);
    		            break;
    		        
    		        default:
    		            $bulkUploadResult->status = KalturaBulkUploadResultStatus::ERROR;
    		            $bulkUploadResult->errorDescription = "Unknown action passed: [".$bulkUploadResult->action ."]";
    		            break;
    		    }
		    }
		    catch (Exception $e)
		    {
		        $requestResults[] = $e;
		    }
		    
		}
		
		$this->unimpersonate();
		// make all the category actions as the partner
		
		if(count($requestResults))
			$this->updateObjectsResults($requestResults, $bulkUploadResultChunk);

		KalturaLog::info("job[{$this->job->id}] finish modifying categories");
	}
	
	/**
	 * Function to create a new category from bulk upload result.
	 * @param KalturaBulkUploadResultCategory $bulkUploadResult
	 */
	protected function createCategoryFromResultAndJobData (KalturaBulkUploadResultCategory $bulkUploadCategoryResult)
	{
	    $category = new KalturaCategory();
	    $category->name = $bulkUploadCategoryResult->name;
	    //$category->owner = $this->job->data->userId;
	    //calculate parentId of the category
	    if ($bulkUploadCategoryResult->relativePath)
	        $category->parentId = $this->calculateParentId($bulkUploadCategoryResult->relativePath);
	        
	    if ($bulkUploadCategoryResult->tags)
	        $category->tags = $bulkUploadCategoryResult->tags;
	        
	    if ($bulkUploadCategoryResult->description)
	        $category->description = $bulkUploadCategoryResult->description;
	        
	    if ($bulkUploadCategoryResult->referenceId)
	        $category->referenceId = $bulkUploadCategoryResult->referenceId; 
	           
	    if ($bulkUploadCategoryResult->contributionPolicy)
	        $category->contributionPolicy = $bulkUploadCategoryResult->contributionPolicy;

	    if ($bulkUploadCategoryResult->privacy)
	        $category->privacy = $bulkUploadCategoryResult->privacy;
	        
	    if ($bulkUploadCategoryResult->appearInList)
	        $category->appearInList = $bulkUploadCategoryResult->appearInList;
	        
	    if ($bulkUploadCategoryResult->inheritanceType)
	        $category->inheritanceType = $bulkUploadCategoryResult->inheritanceType;
	        
	    if ($bulkUploadCategoryResult->owner)
	        $category->owner = $bulkUploadCategoryResult->owner;

	    if (!is_null($bulkUploadCategoryResult->defaultPermissionLevel))
	        $category->defaultPermissionLevel = $bulkUploadCategoryResult->defaultPermissionLevel;

	    if (!is_null($bulkUploadCategoryResult->userJoinPolicy))
	        $category->userJoinPolicy = $bulkUploadCategoryResult->userJoinPolicy;
	        
	    if (!is_null($bulkUploadCategoryResult->partnerSortValue))
	        $category->partnerSortValue = $bulkUploadCategoryResult->partnerSortValue;

	    if ($bulkUploadCategoryResult->partnerData)
	        $category->partnerData = $bulkUploadCategoryResult->partnerData;
	    
	    if (!is_null($bulkUploadCategoryResult->moderation))
	        $category->moderation = $bulkUploadCategoryResult->moderation;
	        
	    return $category;
	}
	
	protected function calculateParentId ($fullname)
	{
	    $parentCategoryFilter = new KalturaCategoryFilter();
	    $parentCategoryFilter->fullNameEqual = $fullname;
	    $parentCategoryIds = $this->kClient->category->listAction($parentCategoryFilter);
	    /* @var $parentCategoryIds KalturaCategoryListResponse*/
	    if (!count($parentCategoryIds->objects))
	    {
	        //Error because the relative path of the new category does not exist
	    }
	    if (count($parentCategoryIds->objects) > 1)
	    {
	        //Error because the relative path of the new category is not unique under the root category.
	    }
	    return $parentCategoryIds->objects[0]->id;
	}
	
	protected function calculateIdToUpdate (KalturaBulkUploadResultCategory $bulkUploadResult)
	{
	    if ($bulkUploadResult->objectId)
	    {
	        return $bulkUploadResult->objectId;
	    }
	    else if ($bulkUploadResult->referenceId)
	    {
	        $categoryFilter = new KalturaCategoryFilter();
	        $categoryFilter->referenceIdEqual = $bulkUploadResult->referenceId;
	        $categoryFilter->fullNameStartsWith = $bulkUploadResult->relativePath;
	        $categoryList = $this->kClient->category->listAction($categoryFilter);
	        if (count($categoryList->objects))
	        {
	            return $categoryList->objects[0]->id;
	        }
	    }
	    
	    return null;
	}
	
	/**
	 * 
	 * Gets the columns for V1 csv file
	 */
	protected function getColumns()
	{
		return array(
		    "action",
		    "categoryId",
		    "name",
		    "relativePath",
		    "tags",
		    "description",
		    "referenceId",
		    "contributionPolicy",
		    "privacy",
		    "inheritanceType",
		    "owner",
			"userJoinPolicy",
		    "appearInList",
		    "defaultPermissionLevel",
		    "partnerSortValue",
		    "partnerData",
		    "moderation",
		);
	}
	
	
    protected function updateObjectsResults($requestResults, $bulkUploadResults)
	{
	    $this->kClient->startMultiRequest();
		KalturaLog::info("Updating " . count($requestResults) . " results");
		
		// checking the created entries
		foreach($requestResults as $index => $requestResult)
		{
			$bulkUploadResult = $bulkUploadResults[$index];
			
			if(is_array($requestResult) && isset($requestResult['code']))
			{
			    $bulkUploadResult->status = KalturaBulkUploadResultStatus::ERROR;
			    $bulkUploadResult->errorType = KalturaBatchJobErrorTypes::KALTURA_API;
				$bulkUploadResult->objectStatus = $requestResult['code'];
				$bulkUploadResult->errorDescription = $requestResult['message'];
				$this->addBulkUploadResult($bulkUploadResult);
				continue;
			}
			
			if($requestResult instanceof Exception)
			{
				$bulkUploadResult->status = KalturaBulkUploadResultStatus::ERROR;
				$bulkUploadResult->errorType = KalturaBatchJobErrorTypes::KALTURA_API;
				$bulkUploadResult->errorDescription = $requestResult->getMessage();
				$this->addBulkUploadResult($bulkUploadResult);
				continue;
			}
			
			// update the results with the new object Id
			if ($requestResult->id)
			    $bulkUploadResult->objectId = $requestResult->id;
			$this->addBulkUploadResult($bulkUploadResult);
		}
		
		$this->kClient->doMultiRequest();
	}
}
<?php
/**
 * Search object tags
 *
 * @service tag
 * @package plugins.tagSearch
 * @subpackage api.services
 */
class TagService extends KalturaBaseService
{   
    public function initService($serviceId, $serviceName, $actionName)
    {
        parent::initService($serviceId, $serviceName, $actionName);
		$this->applyPartnerFilterForClass('Tag');
    }
    
    /**
     * @action search
     * 
     * Action to search tags using a string of 3 letters or more.
     * @param KalturaTagFilter $tagFilter
     * @param KalturaFilterPager $pager
     * @return KalturaTagListResponse
     */
    public function searchAction (KalturaTagFilter $tagFilter, KalturaFilterPager $pager = null)
    {
        if (!$tagFilter)
        {
            $tagFilter = new KalturaTagFilter();
        }
        
        if (!$pager)
        {
            $pager = new KalturaFilterPager();
        }
        
        $tagFilter->validate();
        
        $c = KalturaCriteria::create(TagPeer::OM_CLASS);
        $tagCoreFilter = new TagFilter();
        $tagFilter->toObject($tagCoreFilter);
        $tagCoreFilter->attachToCriteria($c);
        $pager->attachToCriteria($c);
        $tags = TagPeer::doSelect($c);
        
        $searchResponse = new KalturaTagListResponse();
        $searchResponse->objects = KalturaTagArray::fromDbArray($tags);
        $searchResponse->totalCount = $c->getRecordsCount();
        
        return $searchResponse;
    }
    
    /**
     * Action goes over all tags with instanceCount==0 and checks whether they need to be removed from the DB. Returns number of removed tags.
     * @action deletePending
     * @return int
     */
    public function deletePendingAction ()
    {
		TagPeer::setUseCriteriaFilter(false);
    	$c = KalturaCriteria::create(TagPeer::OM_CLASS);
		$filter = new TagFilter();
		$filter->set('_eq_instance_count', 0);
		$filter->attachToCriteria($c);
		$c->applyFilters();
		$count = $c->getRecordsCount();
		
		if (!$count)
		{
			KalturaLog::info ('No tags pending for deletion.');
			return 0;
		}
			
		$deletedTags = 0;
		$tagsForDelete = TagPeer::doSelect($c);
		TagPeer::setUseCriteriaFilter(true);
		
		foreach ($tagsForDelete as $tag)
		{
			/* @var $tag Tag */
			switch ($tag->getObjectType())
		    {
		    	case taggedObjectType::ENTRY:
		    		$deletedTags += $this->resolveEntryTag($tag);
		    		break;
		    	case taggedObjectType::CATEGORY:
		    		$deletedTags += $this->resolveCategoryTag($tag);
		    		break;
		    }
		}
		
		return $deletedTags;
    }
    
	/**
	 * @param Tag $tag
	 * @return int
	 */
	private function resolveEntryTag (Tag $tag)
	{
	    $c = KalturaCriteria::create(entryPeer::OM_CLASS);
	    $c->add(entryPeer::PARTNER_ID, $tag->getPartnerId());
	    if ($tag->getPrivacyContext() != kTagFlowManager::NULL_PC)
	    	$c->addAnd(entryPeer::PRIVACY_BY_CONTEXTS, $tag->getPrivacyContext(), Criteria::LIKE);
			
	    $entryFilter = new entryFilter();
	    $entryFilter->set('_mlikeand_tags', $tag->getTag());
	    $entryFilter->attachToCriteria($c);
	    $c->applyFilters();
	    $count = $c->getRecordsCount();
	    if (!$count)
	    {
	    	$tag->delete();	
	    	return 1;
	    }
	    
	    return 0;
	    
	}
	    
	/**
	 * @param Tag $tag
	 * @return int
	 */
	private function resolveCategoryTag (Tag $tag)
	{
	    $c = KalturaCriteria::create(categoryPeer::OM_CLASS);
	    $c->add(categoryPeer::PARTNER_ID, $tag->getPartnerId());
	    $categoryFilter = new categoryFilter();
	    $categoryFilter->set('_mlikeand_tags', $tag->getTag());
	    $categoryFilter->attachToCriteria($c);
	    $c->applyFilters();
	    $count = $c->getRecordsCount();
	    if (!$count)
	    {
	    	$tag->delete();	
	    	return 1;
	    }
	    
	    return 0;
	}

}
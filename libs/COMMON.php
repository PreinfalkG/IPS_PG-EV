<?

abstract class LogLevel {
    const ALL = 9;
    const TEST = 8;
    const TRACE = 7;
    const COMMUNICATION = 6;
    const DEBUG = 5;
    const INFO = 4;
    const WARN = 3;
    const ERROR = 2;
    const FATAL = 1;
}

abstract class VARIABLE {
    const TYPE_BOOLEAN = 0;
    const TYPE_INTEGER = 1;
    const TYPE_FLOAT = 2;
    const TYPE_STRING = 3;
}


trait EV_COMMON {

    protected function profilingStart($profName) {
        $profAttrCnt = "prof_" . $profName;
        $profAttrDuration = "prof_" . $profName . "_Duration";
        $this->WriteAttributeInteger($profAttrCnt, $this->ReadAttributeInteger($profAttrCnt)+1);
        $this->WriteAttributeFloat($profAttrDuration, microtime(true));

    }

    protected function profilingEnd($profName) {
        $profAttrCnt = "prof_" . $profName . "_OK";
        $profAttrDuration = "prof_" . $profName . "_Duration";
        $this->WriteAttributeInteger($profAttrCnt, $this->ReadAttributeInteger($profAttrCnt)+1);
        $duration = $this->CalcDuration_ms($this->ReadAttributeFloat($profAttrDuration));
        $this->WriteAttributeFloat($profAttrDuration, $duration);			
        SetValue($this->GetIDForIdent("updateCntOk"), GetValue($this->GetIDForIdent("updateCntOk")) + 1);  
    }	
    
    protected function profilingFault($profName, $msg) {
        $profAttrCnt = "prof_" . $profName  . "_NotOK";
        $profAttrDuration = "prof_" . $profName . "_Duration";
        $this->WriteAttributeInteger($profAttrCnt, $this->ReadAttributeInteger($profAttrCnt)+1);
        $this->WriteAttributeFloat($profAttrDuration, -1);	
        SetValue($this->GetIDForIdent("updateCntError"), GetValue($this->GetIDForIdent("updateCntError")) + 1);  
        SetValue($this->GetIDForIdent("updateLastError"), $msg);			
    }	

    public function GetProfilingData(string $caller='?') {
        if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("GetProfilingData [%s] ...", $caller), 0); }
        $profDataArr = [];
        foreach(self::PROF_NAMES as $profName) {
            $arrEntry = array();
            $arrEntry["cntStart"] = $this->ReadAttributeInteger("prof_" . $profName);
            $arrEntry["cntOK"] = $this->ReadAttributeInteger("prof_" . $profName . "_OK");
            $arrEntry["cntNotOk"] = $this->ReadAttributeInteger("prof_" . $profName  . "_NotOK");
            $arrEntry["duration"] = $this->ReadAttributeFloat("prof_" . $profName . "_Duration");
            $profDataArr[$profName] = $arrEntry;
        }
        return $profDataArr;				
    }

    public function GetProfilingDataAsText(string $caller='?') {
        if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("GetProfilingDataAsText [%s] ...", $caller), 0); }
        return print_r($this->GetProfilingData($caller), true);
    }

    public function Reset_ProfilingData(string $caller='?') {
        if($this->logLevel >= LogLevel::INFO) { $this->AddLog(__FUNCTION__, sprintf("Reset_ProfilingData [%s] ...", $caller), 0); }
        foreach(self::PROF_NAMES as $profName) {
            $this->WriteAttributeInteger("prof_" . $profName, 0);
            $this->WriteAttributeInteger("prof_" . $profName . "_OK", 0);
            $this->WriteAttributeInteger("prof_" . $profName  . "_NotOK", 0);
            $this->WriteAttributeFloat("prof_" . $profName . "_Duration", 0);
        }
    }


    protected function GetCategoryID($identName, $categoryName, $parentId, $position=0) {

        $categoryId = @IPS_GetObjectIDByIdent($identName, $parentId);
        if ($categoryId == false) {

            if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, 
                sprintf("Create IPS-Category :: Name: %s | Ident: %s | ParentId: %s", $categoryName, $identName, $parentId), 0); }	

            $categoryId = IPS_CreateCategory();
            IPS_SetParent($categoryId, $parentId);
            IPS_SetIdent($categoryId, $identName);
            IPS_SetName($categoryId, $categoryName);
            IPS_SetPosition($categoryId, $position);
        }  
        return $categoryId;

    }


    protected function GetDummyModuleID($identName, $instanceName, $parentId, $position=0) {

        $instanceId = @IPS_GetObjectIDByIdent($identName, $parentId);
        if ($instanceId == false) {

            if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, 
                sprintf("Create Dummy-Module :: Name: %s | Ident: %s | ParentId: %s", $instanceName, $identName, $parentId), 0); }	

            $instanceId = IPS_CreateInstance("{485D0419-BE97-4548-AA9C-C083EB82E61E}");
            IPS_SetParent($instanceId, $parentId);
            IPS_SetIdent($instanceId, $identName);
            IPS_SetName($instanceId, $instanceName);
            IPS_SetPosition($instanceId, $position);
        }  
        return $instanceId;

    }




    protected function SaveVariableValue($value, $parentId, $varIdent, $varName, $varType=3, $position=0, $varProfile="", $asMaxValue=false) {
			
        $varId = @IPS_GetObjectIDByIdent($varIdent, $parentId);
        if($varId === false) {

            if($varType < 0) {
                switch(gettype($value)) {
                    case "boolean":
                        $varType = 0;
                        break;
                    case "integer":
                        $varType = 1;
                        break;     
                    case "double":
                    case "float":
                        $varType = 1;
                        break;                                                  
                    default:
                        $varType = 3;
                        break;
                }
            }

            if($this->logLevel >= LogLevel::TRACE) { $this->AddLog(__FUNCTION__, 
                sprintf("Create IPS-Variable :: Type: %d | Ident: %s | Profile: %s | Name: %s", $varType, $varIdent, $varProfile, $varName), 0); }	

            $varId = IPS_CreateVariable($varType);
            IPS_SetParent($varId, $parentId);
            IPS_SetIdent($varId, $varIdent);
            IPS_SetName($varId, $varName);
            IPS_SetPosition($varId, $position);
            IPS_SetVariableCustomProfile($varId, $varProfile);
            //AC_SetLoggingStatus ($this->archivInstanzID, $varId, true);
            //IPS_ApplyChanges($this->archivInstanzID);

        }			
        
        if($asMaxValue) {
            $valueTemp = GetValue($varId); 
            if($value > $valueTemp) {
                SetValue($varId, $value); 	
            }

        } else {
            SetValue($varId, $value);  
        }
        return $value;
    }

    protected function CalcDuration_ms(float $timeStart) {
        $duration =  microtime(true) - $timeStart;
        return round($duration*1000,2);
    }	

}


?>
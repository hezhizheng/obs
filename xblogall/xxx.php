<?php

public function salesmanData($request)
{
    $page = isset($request['page']) ? (int)$request['page'] : 1;
    $rows = isset($request['rows']) ? (int)$request['rows'] : 20;

    // for total page
    $resData = [
        'data' => [],
        'current_page' => $page,
        'last_page' => 0,
        'per_page' => $rows,
        'total' => 0,
        'to' => 0,
        'total_data' => [
            'costs' => '0.00',
            'cash_costs' => '0.00',
            'reward_costs' => '0.00',
            'cost' => '0.00',
            'cash_cost' => '0.00',
            'reward_cost' => '0.00',
            'tl_price' => '0.00',
            'tl_total_gmv' => '0.00',
            'pdd_price' => '0.00',
            'pdd_total_gmv' => '0.00',
            'jd_price' => '0.00',
            'jd_total_gmv' => '0.00',
            'wx_price' => '0.00',
            'wx_total_gmv' => '0.00',
            'total_price' => '0.00',
            'total_gmv' => '0.00',
        ]
    ];
    // 昨日
    $y1day = zeroTime(-1);

    if (!empty($request['start_time']) && !empty($request['end_time'])){
        $startTime = $request['start_time'];
        $endTime = $request['end_time'];
    }else{
        $startTime = $y1day;
        $endTime = $y1day;
    }
    $queryTypeList = [
        'advertiser_name', 'advertiser_id', 'company'
    ];

    if (!empty($request['custom_fields'])){
        $customFieldList = is_array($request['custom_fields']) ? $request['custom_fields'] : stringToArr($request['custom_fields']);
    }else{
        $customFieldList = PersonField::$defaultFieldList[PersonField::SALESMAN_DATA_LIST];
    }

    $mediaType = 0;
    if (isset($request['media_type']) && array_key_exists($request['media_type'], YlAdv::$mediaTypeList)){
        $mediaType = intval($request['media_type']);
    }
    $indexTable = YlAdv::$advIndexTables[$mediaType];
    $balanceTable = YlAdv::$advBalanceTables[$mediaType];

    $where = "a.soft_delete = 0 and a.media_type = ".$mediaType;

    if (isset($request['query_type']) && in_array($request['query_type'], $queryTypeList) && isset($request['keyword'])
        && trim($request['keyword']) != ''){
        if ($request['query_type'] == 'advertiser_id'){
            $keywords = stringToArr($request['keyword'], ',', true);
            if (!empty($keywords) && is_array($keywords)){
                $where .= " and a.advertiser_id in (".arrToString($keywords).")";
            }
        }elseif ($request['query_type'] == 'advertiser_name'){
            $keywords = stringToArr($request['keyword']);
            if (!empty($keywords) && is_array($keywords)){
                $where .= " and a.advertiser_name in (".arrToStringByColumn($keywords).")";
            }
        }else{
            $where .= " and a.".$request['query_type']." like '%".trim($request['keyword'])."%'";
        }
    }
    if (!empty($request['salesman_ids'])){
        $salesmanIds = is_array($request['salesman_ids']) ? $request['salesman_ids'] : stringToArr($request['salesman_ids']);
        $where .= " and a.salesman_id in (".arrToString($salesmanIds).")";
    }
    if (!empty($request['customer_cate_ids'])){
        $customerCateIds = is_array($request['customer_cate_ids']) ? $request['customer_cate_ids'] : stringToArr($request['customer_cate_ids']);
        $where .= " and a.customer_cate_id in (".arrToString($customerCateIds).")";
    }
    if (!empty($request['customer_company_ids'])){
        $customerCompanyIds = is_array($request['customer_company_ids']) ? $request['customer_company_ids'] : stringToArr($request['customer_company_ids']);
        $where .= " and a.customer_company_id in (".arrToString($customerCompanyIds).")";
    }
    if (!empty($startTime) && !empty($endTime)){
        if ($startTime == $endTime){
            $where .= " and a.date = ".$startTime;
        }else{
            $where .= " and a.date between ".$startTime." and ".$endTime;
        }
    }

    $column = "a.date, a.advertiser_name, a.advertiser_id, a.salesman_id, a.customer_cate_id, a.customer_company_id, a.platform";

    $sql = "select $column from `adv_daily_record` as a";
    $sql .= " where ".$where;
    $sql .= " group by a.platform, a.salesman_id, a.customer_cate_id, a.customer_company_id, a.advertiser_id";
    $advList = DB::select($sql);
    if (empty($advList)){
        return successObject("操作成功", $resData);
    }
    foreach ($advList as &$value){
        $value = objectToArray($value);
    }
    unset($value);

    $advIds = array_column($advList, 'advertiser_id');
    $oaiWhere = "oai.soft_delete = 0 and oai.advertiser_id in (".arrToString($advIds).")";
    $advWhere = "soft_delete = 0 and advertiser_id in (".arrToString($advIds).")";
    if (!empty($startTime) && !empty($endTime)){
        if ($startTime == $endTime){
            $advWhere .= " and date = ".$startTime;
            $oaiWhere .= " and oai.date = ".$startTime;
        }else{
            $advWhere .= " and date between ".$startTime." and ".$endTime;
            $oaiWhere .= " and oai.date between ".$startTime." and ".$endTime;
        }
    }

    $orderService = new OrderService();
    $osqlMap = $orderService->getSqlMap([
        'group_by' => 'o.advertiser_id',
        'start_time' => $startTime,
        'end_time' => $endTime,
        'order_time_type' => 0,
        'media_type' => $mediaType
    ]);
    $pddSql = $osqlMap['pdd_sql'];
    $jdSql = $osqlMap['jd_sql'];
    $tlSql = $osqlMap['tl_sql'];
    $wxSql = $osqlMap['wx_sql'];

    $totalCosts = 0;
    $totalCashCosts = 0;
    $totalRewardCosts = 0;
    $tlPrice = 0;
    $pddPrice = 0;
    $jdPrice = 0;
    $wxPrice = 0;
    $totalPrice = 0;
    $totalGmv = 0;
    $tlTotalGmv = 0;
    $pddTotalGmv = 0;
    $jdTotalGmv = 0;
    $wxTotalGmv = 0;

    // 账户消耗
    $oaiColumn = "sum(oai.cost) as costs, oai.advertiser_id";
    $oaiColumn .= ", tl.total_gmv as tl_total_gmv, tl.all_gmv as tl_price, pdd.total_gmv as pdd_total_gmv, pdd.all_gmv as pdd_price, jd.total_gmv as jd_total_gmv, jd.all_gmv as jd_price, wx.total_gmv as wx_total_gmv, wx.all_gmv as wx_price";
    $oaiColumn .= ", (COALESCE(pdd.all_gmv, 0) + COALESCE(jd.all_gmv, 0) + COALESCE(tl.all_gmv, 0) + COALESCE(wx.all_gmv, 0)) as total_price";
    $oaiColumn .= ", (COALESCE(pdd.total_gmv, 0) + COALESCE(jd.total_gmv, 0) + COALESCE(tl.total_gmv, 0) + COALESCE(wx.total_gmv, 0)) as total_gmv";


    $oaiSql = "select $oaiColumn from `$indexTable` as oai";
    $oaiSql .= " left join ($pddSql) as pdd on oai.advertiser_id = pdd.advertiser_id";
    $oaiSql .= " left join ($jdSql) as jd on oai.advertiser_id = jd.advertiser_id";
    $oaiSql .= " left join ($tlSql) as tl on oai.advertiser_id = tl.advertiser_id";
    $oaiSql .= " left join ($wxSql) as wx on oai.advertiser_id = wx.advertiser_id";
    $oaiSql .= " where ".$oaiWhere;
    $oaiSql .= " group by oai.advertiser_id";
    $oaiList = DB::select($oaiSql);
    foreach ($oaiList as &$value){
        $value = objectToArray($value);
    }
    unset($value);
    $oaiListMap = toMap($oaiList, 'advertiser_id');

    // 账户现金消耗
    $oabSql = "select sum(cash_cost) as cash_costs, sum(reward_cost) as reward_costs, advertiser_id from `$balanceTable`";
    $oabSql .= " where ".$advWhere;
    $oabSql .= " group by advertiser_id";
    $oabList = DB::select($oabSql);
    foreach ($oabList as &$value){
        $value = objectToArray($value);
    }
    unset($value);
    $oabListMap = toMap($oabList, 'advertiser_id');

    $advListMap = toMapList($advList, 'platform');
    foreach ($advListMap as &$value) {
        // 销售
        $value = toMapList($value, 'salesman_id');
        foreach ($value as &$v) {
            // 客户分类
            $v = toMapList($v, 'customer_cate_id');
            foreach ($v as &$r) {
                $r = toMapList($r, 'customer_company_id');
            }
            unset($r);
        }
        unset($v);
    }
    unset($value);

    // 销售
    $salesmanList = Admin::getByField(['role_type' => 1], ['id', 'name'])->toArray();
    // 客户分类
    $cateList = CustomerCate::getByField([], ['id', 'name'])->toArray();
    // 客户主体
    $companyList = CustomerCompany::getByField([], ['id', 'name'])->toArray();

    $index = 1;
    $list = [];
    $exportList = [];

    $platformList = [
        0 => '淘宝',
        2 => '拼多多',
        1 => '京东',
        5 => 't-shop微信商城'
    ];

    foreach ($platformList as $platform => $platformName){
        $dateAdvListMap = [];
        if (!empty($advListMap[$platform])){
            $dateAdvListMap = $advListMap[$platform];
        }

        // 日数据
        $eCost = 0;
        $eCashCost = 0;
        $eRewardCost = 0;
        $eTlPrice = 0;
        $ePddPrice = 0;
        $eJdPrice = 0;
        $eWxPrice = 0;
        $eTotalPrice = 0;
        $eTotalGmv = 0;
        $eTlTotalGmv = 0;
        $ePddTotalGmv = 0;
        $eJdTotalGmv = 0;
        $eWxTotalGmv = 0;

        $eDto = new SalesmanDataDTO();
        $eDto->setIndex($index);
        $eDto->setLevel(1);
        $eDto->setName($platformName);
        $index++;
        // 销售
        $sList = [];
        foreach ($salesmanList as $salesman){
            if (empty($dateAdvListMap[$salesman['id']])) continue;

            $sAdvListMap = $dateAdvListMap[$salesman['id']];

            $sCost = 0;
            $sCashCost = 0;
            $sRewardCost = 0;
            $sTlPrice = 0;
            $sPddPrice = 0;
            $sJdPrice = 0;
            $sWxPrice = 0;
            $sTotalPrice = 0;
            $sTotalGmv = 0;
            $sTlTotalGmv = 0;
            $sPddTotalGmv = 0;
            $sJdTotalGmv = 0;
            $sWxTotalGmv = 0;

            $sDto = new SalesmanDataDTO();
            $sDto->setIndex($index);
            $sDto->setLevel(2);
            $sDto->setName($salesman['name']);
            $index++;
            // 客户分类
            $cList = [];
            foreach ($cateList as $cate){
                if (empty($sAdvListMap[$cate['id']])) continue;

                $cAdvListMap = $sAdvListMap[$cate['id']];

                $cCost = 0;
                $cCashCost = 0;
                $cRewardCost = 0;
                $cTlPrice = 0;
                $cPddPrice = 0;
                $cJdPrice = 0;
                $cWxPrice = 0;
                $cTotalPrice = 0;
                $cTotalGmv = 0;
                $cTlTotalGmv = 0;
                $cPddTotalGmv = 0;
                $cJdTotalGmv = 0;
                $cWxTotalGmv = 0;

                $cDto = new SalesmanDataDTO();
                $cDto->setIndex($index);
                $cDto->setLevel(3);
                $cDto->setName($cate['name']);
                $index++;
                // 客户主体
                $mList = [];
                foreach ($companyList as $company){
                    if (empty($cAdvListMap[$company['id']])) continue;

                    $mAdvListMap = $cAdvListMap[$company['id']];

                    $mCost = 0;
                    $mCashCost = 0;
                    $mRewardCost = 0;
                    $mTlPrice = 0;
                    $mPddPrice = 0;
                    $mJdPrice = 0;
                    $mWxPrice = 0;
                    $mTotalPrice = 0;
                    $mTotalGmv = 0;
                    $mTlTotalGmv = 0;
                    $mPddTotalGmv = 0;
                    $mJdTotalGmv = 0;
                    $mWxTotalGmv = 0;

                    $mDto = new SalesmanDataDTO();
                    $mDto->setIndex($index);
                    $mDto->setLevel(4);
                    $mDto->setName($company['name']);
                    $index++;
                    // 账户
                    $aList = [];
                    foreach ($mAdvListMap as $adv){
                        $aCost = 0;
                        $aCashCost = 0;
                        $aRewardCost = 0;
                        $aTlPrice = 0;
                        $aPddPrice = 0;
                        $aJdPrice = 0;
                        $aWxPrice = 0;
                        $aTotalPrice = 0;
                        $aTotalGmv = 0;
                        $aTlTotalGmv = 0;
                        $aPddTotalGmv = 0;
                        $aJdTotalGmv = 0;
                        $aWxTotalGmv = 0;
                        if (!empty($oaiListMap[$adv['advertiser_id']])){
                            $oai = $oaiListMap[$adv['advertiser_id']];
                            if ($mediaType == 2){
                                $aCost = round(intval($oai['costs']) / 1000, 3);
                            }else{
                                $aCost = round(intval($oai['costs']) / 100, 2);
                            }
                            $aTlPrice = round(intval($oai['tl_price']) / 100, 2);
                            $aPddPrice = round(intval($oai['pdd_price']) / 100, 2);
                            $aJdPrice = round(intval($oai['jd_price']) / 100, 2);
                            $aWxPrice = round(intval($oai['wx_price']) / 100, 2);
                            $aTotalPrice = round(intval($oai['total_price']) / 100, 2);
                            $aTotalGmv = round(intval($oai['total_gmv']) / 100, 2);
                            $aTlTotalGmv = round(intval($oai['tl_total_gmv']) / 100, 2);
                            $aPddTotalGmv = round(intval($oai['pdd_total_gmv']) / 100, 2);
                            $aJdTotalGmv = round(intval($oai['jd_total_gmv']) / 100, 2);
                            $aWxTotalGmv = round(intval($oai['wx_total_gmv']) / 100, 2);
                        }
                        if (!empty($oabListMap[$adv['advertiser_id']])){
                            $oab = $oabListMap[$adv['advertiser_id']];
                            $aCashCost = round(intval($oab['cash_costs']) / 100, 2);
                            $aRewardCost = round(intval($oab['reward_costs']) / 100, 2);
                        }

                        if ($aCost > 0 || $aCashCost > 0 || $aRewardCost > 0 || $aTotalPrice > 0 || $aTotalGmv > 0){
                            $exportList[] = [
                                'advertiser_name' => $adv['advertiser_name'],
                                'advertiser_id' => $adv['advertiser_id'],
                                'platform_title' => $platformName,
                                'salesman_name' => $salesman['name'],
                                'customer_cate_name' => $cate['name'],
                                'customer_company_name' => $company['name'],
                                'media_type_title' => YlAdv::$mediaTypeList[$mediaType],
                                'cost' => $aCost,
                                'cash_cost' => $aCashCost,
                                'reward_cost' => $aRewardCost,
                                'tl_price' => $aTlPrice,
                                'pdd_price' => $aPddPrice,
                                'jd_price' => $aJdPrice,
                                'wx_price' => $aWxPrice,
                                'total_price' => $aTotalPrice,
                                'total_gmv' => $aTotalGmv,
                                'tl_total_gmv' => $aTlTotalGmv,
                                'pdd_total_gmv' => $aPddTotalGmv,
                                'jd_total_gmv' => $aJdTotalGmv,
                                'wx_total_gmv' => $aWxTotalGmv,
                            ];
                        }

                        $aDto = new SalesmanDataDTO();
                        $aDto->setIndex($index);
                        $aDto->setLevel(5);
                        $aDto->setName($adv['advertiser_name'].'('.$adv['advertiser_id'].')');
                        if ($mediaType == 2){
                            $aDto->setCost(moneyFormat($aCost, 3));
                        }else{
                            $aDto->setCost(moneyFormat($aCost));
                        }
                        $aDto->setCashCost(moneyFormat($aCashCost));
                        $aDto->setRewardCost(moneyFormat($aRewardCost));
                        $aDto->setTlPrice(moneyFormat($aTlPrice));
                        $aDto->setPddPrice(moneyFormat($aPddPrice));
                        $aDto->setJdPrice(moneyFormat($aJdPrice));
                        $aDto->setWxPrice(moneyFormat($aWxPrice));
                        $aDto->setTotalPrice(moneyFormat($aTotalPrice));
                        $aDto->setTotalGmv(moneyFormat($aTotalGmv));
                        $aDto->setTlTotalGmv(moneyFormat($aTlTotalGmv));
                        $aDto->setPddTotalGmv(moneyFormat($aPddTotalGmv));
                        $aDto->setJdTotalGmv(moneyFormat($aJdTotalGmv));
                        $aDto->setWxTotalGmv(moneyFormat($aWxTotalGmv));
                        $aDto->setSortCost($aCost);
                        $index++;

                        if ($aCost > 0 || $aCashCost > 0 || $aRewardCost > 0 || $aTotalPrice > 0){
                            $aList[] = dtoToArray($aDto);
                        }

                        $mCost += $aCost;
                        $mCashCost += $aCashCost;
                        $mRewardCost += $aRewardCost;
                        $mTlPrice += $aTlPrice;
                        $mPddPrice += $aPddPrice;
                        $mJdPrice += $aJdPrice;
                        $mWxPrice += $aWxPrice;
                        $mTotalPrice += $aTotalPrice;
                        $mTotalGmv += $aTotalGmv;
                        $mTlTotalGmv += $aTlTotalGmv;
                        $mPddTotalGmv += $aPddTotalGmv;
                        $mJdTotalGmv += $aJdTotalGmv;
                        $mWxTotalGmv += $aWxTotalGmv;
                    }
                    array_multisort(array_column($aList, 'sort_cost'), SORT_DESC, $aList);
                    if ($mediaType == 2){
                        $mDto->setCost(moneyFormat($mCost, 3));
                    }else{
                        $mDto->setCost(moneyFormat($mCost));
                    }
                    $mDto->setCashCost(moneyFormat($mCashCost));
                    $mDto->setRewardCost(moneyFormat($mRewardCost));
                    $mDto->setTlPrice(moneyFormat($mTlPrice));
                    $mDto->setPddPrice(moneyFormat($mPddPrice));
                    $mDto->setJdPrice(moneyFormat($mJdPrice));
                    $mDto->setWxPrice(moneyFormat($mWxPrice));
                    $mDto->setTotalPrice(moneyFormat($mTotalPrice));
                    $mDto->setTotalGmv(moneyFormat($mTotalGmv));
                    $mDto->setTlTotalGmv(moneyFormat($mTlTotalGmv));
                    $mDto->setPddTotalGmv(moneyFormat($mPddTotalGmv));
                    $mDto->setJdTotalGmv(moneyFormat($mJdTotalGmv));
                    $mDto->setWxTotalGmv(moneyFormat($mWxTotalGmv));
                    $mDto->setSortCost($mCost);
                    $mDto->setChildren($aList);
                    if (!empty($aList)){
                        $mList[] = dtoToArray($mDto);
                    }
                    $cCost += $mCost;
                    $cCashCost += $mCashCost;
                    $cRewardCost += $mRewardCost;
                    $cTlPrice += $mTlPrice;
                    $cPddPrice += $mPddPrice;
                    $cJdPrice += $mJdPrice;
                    $cWxPrice += $mWxPrice;
                    $cTotalPrice += $mTotalPrice;
                    $cTotalGmv += $mTotalGmv;
                    $cTlTotalGmv += $mTlTotalGmv;
                    $cPddTotalGmv += $mPddTotalGmv;
                    $cJdTotalGmv += $mJdTotalGmv;
                    $cWxTotalGmv += $mWxTotalGmv;
                }
                array_multisort(array_column($mList, 'sort_cost'), SORT_DESC, $mList);
                if ($mediaType == 2){
                    $cDto->setCost(moneyFormat($cCost, 3));
                }else{
                    $cDto->setCost(moneyFormat($cCost));
                }
                $cDto->setCashCost(moneyFormat($cCashCost));
                $cDto->setRewardCost(moneyFormat($cRewardCost));
                $cDto->setTlPrice(moneyFormat($cTlPrice));
                $cDto->setPddPrice(moneyFormat($cPddPrice));
                $cDto->setJdPrice(moneyFormat($cJdPrice));
                $cDto->setWxPrice(moneyFormat($cWxPrice));
                $cDto->setTotalPrice(moneyFormat($cTotalPrice));
                $cDto->setTotalGmv(moneyFormat($cTotalGmv));
                $cDto->setTlTotalGmv(moneyFormat($cTlTotalGmv));
                $cDto->setPddTotalGmv(moneyFormat($cPddTotalGmv));
                $cDto->setJdTotalGmv(moneyFormat($cJdTotalGmv));
                $cDto->setWxTotalGmv(moneyFormat($cWxTotalGmv));
                $cDto->setSortCost($cCost);
                $cDto->setChildren($mList);

                if (!empty($mList)){
                    $cList[] = dtoToArray($cDto);
                }

                $sCost += $cCost;
                $sCashCost += $cCashCost;
                $sRewardCost += $cRewardCost;
                $sTlPrice += $cTlPrice;
                $sPddPrice += $cPddPrice;
                $sJdPrice += $cJdPrice;
                $sWxPrice += $cWxPrice;
                $sTotalPrice += $cTotalPrice;
                $sTotalGmv += $cTotalGmv;
                $sTlTotalGmv += $cTlTotalGmv;
                $sPddTotalGmv += $cPddTotalGmv;
                $sJdTotalGmv += $cJdTotalGmv;
                $sWxTotalGmv += $cWxTotalGmv;
            }
            array_multisort(array_column($cList, 'sort_cost'), SORT_DESC, $cList);
            if ($mediaType == 2){
                $sDto->setCost(moneyFormat($sCost, 3));
            }else{
                $sDto->setCost(moneyFormat($sCost));
            }
            $sDto->setCashCost(moneyFormat($sCashCost));
            $sDto->setRewardCost(moneyFormat($sRewardCost));
            $sDto->setTlPrice(moneyFormat($sTlPrice));
            $sDto->setPddPrice(moneyFormat($sPddPrice));
            $sDto->setJdPrice(moneyFormat($sJdPrice));
            $sDto->setWxPrice(moneyFormat($sWxPrice));
            $sDto->setTotalPrice(moneyFormat($sTotalPrice));
            $sDto->setTotalGmv(moneyFormat($sTotalGmv));
            $sDto->setTlTotalGmv(moneyFormat($sTlTotalGmv));
            $sDto->setPddTotalGmv(moneyFormat($sPddTotalGmv));
            $sDto->setJdTotalGmv(moneyFormat($sJdTotalGmv));
            $sDto->setWxTotalGmv(moneyFormat($sWxTotalGmv));
            $sDto->setSortCost($sCost);
            $sDto->setChildren($cList);
            if (!empty($cList)){
                $sList[] = dtoToArray($sDto);
            }
            $eCost += $sCost;
            $eCashCost += $sCashCost;
            $eRewardCost += $sRewardCost;
            $eTlPrice += $sTlPrice;
            $ePddPrice += $sPddPrice;
            $eJdPrice += $sJdPrice;
            $eWxPrice += $sWxPrice;
            $eTotalPrice += $sTotalPrice;
            $eTotalGmv += $sTotalGmv;
            $eTlTotalGmv += $sTlTotalGmv;
            $ePddTotalGmv += $sPddTotalGmv;
            $eJdTotalGmv += $sJdTotalGmv;
            $eWxTotalGmv += $sWxTotalGmv;
        }
        array_multisort(array_column($sList, 'sort_cost'), SORT_DESC, $sList);
        if ($mediaType == 2){
            $eDto->setCost(moneyFormat($eCost, 3));
        }else{
            $eDto->setCost(moneyFormat($eCost));
        }
        $eDto->setCashCost(moneyFormat($eCashCost));
        $eDto->setRewardCost(moneyFormat($eRewardCost));
        $eDto->setTlPrice(moneyFormat($eTlPrice));
        $eDto->setPddPrice(moneyFormat($ePddPrice));
        $eDto->setJdPrice(moneyFormat($eJdPrice));
        $eDto->setWxPrice(moneyFormat($eWxPrice));
        $eDto->setTotalPrice(moneyFormat($eTotalPrice));
        $eDto->setTotalGmv(moneyFormat($eTotalGmv));
        $eDto->setTlTotalGmv(moneyFormat($eTlTotalGmv));
        $eDto->setPddTotalGmv(moneyFormat($ePddTotalGmv));
        $eDto->setJdTotalGmv(moneyFormat($eJdTotalGmv));
        $eDto->setWxTotalGmv(moneyFormat($eWxTotalGmv));
        $eDto->setSortCost($eCost);
        $eDto->setChildren($sList);

        $totalCosts += $eCost;
        $totalCashCosts += $eCashCost;
        $totalRewardCosts += $eRewardCost;
        $tlPrice += $eTlPrice;
        $pddPrice += $ePddPrice;
        $jdPrice += $eJdPrice;
        $wxPrice += $eWxPrice;
        $totalPrice += $eTotalPrice;
        $totalGmv += $eTotalGmv;
        $tlTotalGmv += $eTlTotalGmv;
        $pddTotalGmv += $ePddTotalGmv;
        $jdTotalGmv += $eJdTotalGmv;
        $wxTotalGmv += $eWxTotalGmv;

        $list[] = dtoToArray($eDto);
    }
    array_multisort(array_column($list, 'sort_cost'), SORT_DESC, $list);
    $resData['data'] = $list;

    if ($mediaType == 2){
        $resData['total_data']['costs'] = moneyFormat($totalCosts, 3);
        $resData['total_data']['cost'] = moneyFormat($totalCosts, 3);
    }else{
        $resData['total_data']['costs'] = moneyFormat($totalCosts);
        $resData['total_data']['cost'] = moneyFormat($totalCosts);
    }
    $resData['total_data']['cash_costs'] = moneyFormat($totalCashCosts);
    $resData['total_data']['reward_costs'] = moneyFormat($totalRewardCosts);
    $resData['total_data']['cash_cost'] = moneyFormat($totalCashCosts);
    $resData['total_data']['reward_cost'] = moneyFormat($totalRewardCosts);
    $resData['total_data']['tl_price'] = moneyFormat($tlPrice);
    $resData['total_data']['pdd_price'] = moneyFormat($pddPrice);
    $resData['total_data']['jd_price'] = moneyFormat($jdPrice);
    $resData['total_data']['wx_price'] = moneyFormat($wxPrice);
    $resData['total_data']['total_price'] = moneyFormat($totalPrice);
    $resData['total_data']['total_gmv'] = moneyFormat($totalGmv);
    $resData['total_data']['tl_total_gmv'] = moneyFormat($tlTotalGmv);
    $resData['total_data']['pdd_total_gmv'] = moneyFormat($pddTotalGmv);
    $resData['total_data']['jd_total_gmv'] = moneyFormat($jdTotalGmv);
    $resData['total_data']['wx_total_gmv'] = moneyFormat($wxTotalGmv);

    if (isset($request['export']) && $request['export'] == 1) {
        $resData = ['data' => $exportList];

        $exportField = [
            'advertiser_name' => '广告账户名',
            'advertiser_id' => '广告账户ID',
            'platform_title' => '平台',
            'salesman_name' => '销售',
            'customer_cate_name' => '客户分类',
            'customer_company_name' => '广告主',
            'media_type_title' => '媒体',
        ];
        $exportField = $this->getExportFields($customFieldList,$exportField);
        $resData['custome_field'] = $exportField;
        $resData['file_name'] = '销售数据';

        return successObject('操作成功', $resData);
    }

    return successObject("操作成功", $resData);
}
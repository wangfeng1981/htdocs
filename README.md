<?php 
define('IN_ECS', true);
/* 取得当前ecshop所在的根目录 */
define('ROOT_PATH2', str_replace('api2', '', str_replace('\\', '/', dirname(__FILE__))));
require(ROOT_PATH2.'includes/init.php');
require_once('./wftools.php');
require_once(ROOT_PATH2 . 'includes/cls_json.php');
include_once(ROOT_PATH2 . 'includes/lib_goods.php');

header("Content-type: application/json; charset=UTF-8"); 
$json = new JSON;
$results = array();
$results['error'] = '' ;
$results['state'] = 0 ;
$GLOBALS['error_wf'] = '' ;
$GLOBALS['state_wf'] = 0 ;

//_POST _POST _POST 
//action: bidlist bid full short my(我成功的拍卖)
$action = isset($_POST['action']) ? $_POST['action'] : 'empty';
$user_id = isset($_POST['user_id']) ? intval(trim($_POST['user_id'])) : 0;
$user_pass = isset($_POST['password']) ? trim($_POST['password']) : '';
//拍品ID
$auc_id = isset($_POST['auc_id']) ? intval($_POST['auc_id']) : 0;
//用户出价
$bid_price = isset($_POST['price']) ? round(floatval($_POST['price']), 2) : 0;
//
$page = isset($_POST['page']) ? intval($_POST['page']) : 1;
$page_size = isset($_POST['page_size']) ? intval($_POST['page_size']) : 30;

//token  
$token = isset($_POST['token']) ? $_POST['token'] : '';

//auction state 0-my bid , 1-my success , 2 - my watch .
$auc_state=isset($_POST['auc_state']) ? intval($_POST['auc_state']) : 0;


$results['a_id'] = (string)$auc_id ;    
$results['action'] = $action ;
$results['auc_state'] = (string)$auc_state ;
$results['page'] = (string)$page ;
$results['page_size'] = (string)$page_size ;

// $results['error'] = 'xxx' ;
// $results['state'] = 1 ;
//die($json->encode($results));



if ($_POST['action'] == 'bid')
{
    include_once(ROOT_PATH2 . 'includes/lib_order.php');
    if( wft_user_ok($user_id, $user_pass)==false )
    {
      $results['error'] = '用户名或密码错误，请重新登录。' ;
      $results['state'] = 2 ;
      die($json->encode($results));
    }
    $userinfo = user_info_wf($user_id) ;
    $rank = $userinfo['user_rank'] ;

    if( $auc_id <= 0 )
    {// 取得参数：拍卖活动id 
      $results['error'] = '拍品ID未指定' ;
      $results['state'] = 10 ;
      die($json->encode($results));
    }

    /* 取得拍卖活动信息 */
    $auction = wft_auction_info($auc_id , $user_id );
    if (empty($auction))
    {
        $results['error'] = '获取拍卖活动信息失败' ;
        $results['state'] = 11 ;
        die($json->encode($results));
    }
    if(!$auction['allowed']) 
    {
        $results['event_user_rank'] = $auction['event_user_rank'] ;
        $results['user_rank'] = (string)$rank ;
        $results['error'] = '用户等级不足' ;
        $results['state'] = 12 ;
        die($json->encode($results));
    }

    $overtime_length = $GLOBALS['_CFG']['auction_overtime_length'];//300
    if( empty($overtime_length) ) $overtime_length = 300 ;//20150701 wangfeng

    //如果最后出价的时间据结束时间不足5分钟时，该件拍品（不影响其他拍品）自动延长5分钟。
    //如果在延长的5分钟内，又有人出价，那么继续延长5分钟，以此类推.
    //v2.1 不在延时!
    // $now = time() ; //UTC //$now = gmtime();
    /* v2.1 不在延时!
    $latest_bid = $auction['last_bid'];
    $lastest_bid_time = $latest_bid['bid_time'];
    $auction_end_time = $auction['end_time'];
    $event_info = get_event_info($auction['event_id']); // lib_common.php
    if (empty($event_info['disable_overtime'])) {
      if($lastest_bid_time + $overtime_length > $auction_end_time) {
        $overtime_count = ceil(($lastest_bid_time + $overtime_length - $auction_end_time)/$overtime_length);
        $overtime_end_time = $auction_end_time+$overtime_count*$overtime_length;
        if($overtime_end_time >= $now && $auction_end_time < $now) {
          $auction['status_no'] = 9;//延时状态
        }
      }
    } v2.1 不在延时! */

    //v2.2 采用延时
    $auction['yanshi'] = '0' ;
    $now = gmtime();
    $utcnow = time() ;
    $latest_bid = $auction['last_bid'];
    $lastest_bid_time = $latest_bid['bid_time'];
    $auction_end_time = $auction['end_time'];
    $event_info = get_event_info($auction['event_id']); // lib_common.php
    if (empty($event_info['disable_overtime'])) {
      if($lastest_bid_time + $overtime_length > $auction_end_time) {
        $overtime_count = ceil(($lastest_bid_time + $overtime_length - $auction_end_time)/$overtime_length);
        $overtime_end_time = $auction_end_time+$overtime_count*$overtime_length;
        if($overtime_end_time >= $utcnow && $auction_end_time < $utcnow) {
          $auction['status_no'] = 9;//延时状态
          $auction['yanshi'] =  $overtime_end_time ; //延时时间 utc 20150629 wangfeng
        }
      }
    }

    // 活动是否正在进行 或者 已经延时
    if ($auction['status_no'] != UNDER_WAY && $auction['status_no'] != 9)
    {
        if( $auction['status_no']==0 )
            $results['error'] = '拍卖尚未开始' ;
        else
            $results['error'] = '拍卖已结束' ;
        $results['state'] = 13 ;
        die($json->encode($results));
    }

    if ($userinfo['is_validated'] == 0 && $userinfo['mobile_is_validated'] == 0 && $userinfo['verify'] == 0) 
    {
        $results['error'] = '用户未验证' ;
        $results['state'] = 14 ;
        die($json->encode($results));
    }
    $results['status_no']=$auction['status_no'] ;

    // 取得出价
    if ($bid_price <= 0)
    {
        $results['error'] = '出价无效' ;
        $results['state'] = 15 ;
        die($json->encode($results));
    }

    // 如果有一口价且出价大于等于一口价，则按一口价算 
    $is_price_end = false; // 出价是否ok
    if ($auction['end_price'] > 0)
    {
        if ($bid_price >= $auction['end_price'])
        {
            $bid_price = $auction['end_price'];
            $is_price_end = true;
        }
    }

    // 出价是否有效：区分第一次和非第一次 
    if (!$is_price_end)
    {
        if ($auction['bid_user_count'] == 0)
        {
            // 第一次要大于等于起拍价 
            $min_price = $auction['start_price'] + $auction['amplitude'];
        }
        else
        {
            // 非第一次出价要大于等于最高价加上加价幅度，但不能超过一口价 
            $auction['amplitude'] = $auction['amplitude'] > 0 ? $auction['amplitude'] : 1;//david
            if ($auction['start_price'] > $auction['last_bid']['bid_price']) 
            {
              $min_price = $auction['start_price'] + $auction['amplitude'];
            } 
            else 
            {
              $min_price = $auction['last_bid']['bid_price'] + $auction['amplitude'];
            }
            if ($auction['end_price'] > 0)//一口价
            {
                $min_price = min($min_price, $auction['end_price']);
            }
        }
        if ($bid_price < $min_price) 
        {
          $results['error'] = '出价不能低于¥'.$min_price ;
          $results['state'] = 16 ;
          die($json->encode($results));
        }
    }

    // 检查联系两次拍卖人是否相同 
    if ($auction['last_bid']['bid_user'] == $user_id && $bid_price != $auction['end_price'])
    {
        $results['error'] = '不能重复出价' ;
        $results['state'] = 17 ;
        die($json->encode($results));
    }

    // 是否需要保证金 
    if ($auction['deposit'] > 0)
    {
        // 可用资金够吗 
        if ($userinfo['user_money'] + $userinfo['credit_line'] < $auction['deposit'])
        {
            $results['error'] = '保证金或信用额度不足,请充值后再试' ;
            $results['state'] = 18 ;
            die($json->encode($results));
        }
        // 如果不是第一个出价，解冻上一个用户的保证金
        if ($auction['bid_user_count'] > 0)
        {
            log_account_change(
              $auction['last_bid']['bid_user'], $auction['deposit'], (-1) * $auction['deposit'],
                0, 0, sprintf($_LANG['au_unfreeze_deposit'], $auction['act_name'])
            );
        }
        // 冻结当前用户的保证金 
        log_account_change($user_id, (-1) * $auction['deposit'], $auction['deposit'],0, 0, sprintf($_LANG['au_freeze_deposit'], $auction['act_name']));
    }
    // 插入出价记录  
    $auction_log = array(
        'act_id'    => $auc_id,
        'bid_user'  => $user_id,
        'bid_price' => $bid_price,
        'bid_time'  => gmtime()
    );// use gmtime ok.
    $GLOBALS['db']->autoExecute($ecs->table('auction_log'), $auction_log, 'INSERT');


    // 查看用户是否已经关注该件拍品 如果没关注那么出价后自动关注该件商品
    if( wft_watch_on($user_id , 2 , $auc_id )==false )
        wft_watch_add($user_id , 2 , $auc_id );
    // 将拍品添加到通知队列
    wft_add_need_notify( 2 , $auc_id ) ;

    // 出价是否等于一口价 如果end_price可能等于0 
    if ($bid_price >= $auction['end_price'] && $auction['end_price']>0 )
    {
        // 结束拍卖活动 
        $sql = "UPDATE " . $ecs->table('goods_activity') . " SET is_finished = 1 WHERE act_id = '$auc_id' LIMIT 1";
        $GLOBALS['db']->query($sql);
        $results['is_finished'] = '1' ;
        $results['status_no'] = '2' ;
    }
    $results['act_id'] = (string)$auction_log['act_id'] ;
    $results['new_user_id'] = (string)$auction_log['user_id'] ;
    if( empty($userinfo['avatar']) )
        $results['new_user_avatar'] = wft_avatarByUserId($auction_log['user_id']);
    else
        $results['new_user_avatar'] ='http://www.hihey.com/'.$userinfo['avatar'] ;
    $results['new_user_name'] = $userinfo['user_name'];
    $results['new_time'] = (string)($auction_log['bid_time']+28800  ) ;//utc
    $results['new_price'] = (string)$bid_price ;
    $results['error'] = '' ;
    $results['state'] = 100 ;
    exit($json->encode($results));
}
elseif ( $action=='short')//简短描述
{
    include_once(ROOT_PATH2 . 'includes/lib_order.php');
    
    if( $auc_id <= 0 )
    {// 取得参数：拍卖活动id 
      $results['error'] = '拍品ID未指定' ;
      $results['state'] = 10 ;
      die($json->encode($results));
    }

    $tauction = wft_auction_info($auc_id,0) ;
    
    $overtime_length = $GLOBALS['_CFG']['auction_overtime_length'];//300
    if( empty($overtime_length) ) $overtime_length = 300 ;//20150701 wangfeng

    //v2.2 采用延时
    $tauction['yanshi'] = '0' ;
    $now = time() ;
    $latest_bid = $tauction['last_bid'];
    $lastest_bid_time = $latest_bid['bid_time'];
    $auction_end_time = $tauction['end_time'];
    $event_info = get_event_info($tauction['event_id']); // lib_common.php
    if (empty($event_info['disable_overtime'])) {
      if($lastest_bid_time + $overtime_length > $auction_end_time) {
        $overtime_count = ceil(($lastest_bid_time + $overtime_length - $auction_end_time)/$overtime_length);
        $overtime_end_time = $auction_end_time+$overtime_count*$overtime_length;
        if($overtime_end_time >= $now && $auction_end_time < $now) {
          $tauction['status_no'] = 9;//延时状态
          $tauction['yanshi'] = $overtime_end_time ; //延时时间 utc 20150629 wangfeng
        }
      }
    }
    $results['a_status'] = (string)$tauction['status_no'] ;
    $results['yanshi'] = $tauction['yanshi'] ;

    //as:auction_status
    // 0 未开始 ， 1 进行中 ， 2 已结束未处理 ， 3 已结束已处理 , 9 延时拍卖中
    if( $tauction['bid_user_count']==0 )
    {
      $results['current_price'] = $tauction['current_price'] ;
      $results['bid_time'] = '0' ;
      $results['user_name'] = '' ;
      $results['avatar'] = '' ;
      $results['user_id'] = '0' ;
    }else
    {
      $results['current_price'] = $tauction['current_price'] ;
      $results['bid_time'] = $tauction['last_bid']['bid_time'] ;
      $results['user_name'] = $tauction['last_bid']['user_name'] ;
      $results['avatar'] = $tauction['last_bid']['avatar'] ;
      $results['user_id'] = $tauction['last_bid']['user_id'] ;
    }
    $results['a_order'] = $tauction['order_count'] ;

    // is watching
    $results['watching'] =  wft_watch_on($user_id,2,$auc_id )?'1':'0' ;

    $results['error'] = '' ;
    $results['state'] = 100 ;
    exit($json->encode($results));
}
elseif ( $action=='full')//全描述
{
    include_once(ROOT_PATH2 . 'includes/lib_order.php');
    
    if( $auc_id <= 0 )
    {// 取得参数：拍卖活动id 
      $results['error'] = '拍品ID未指定' ;
      $results['state'] = 10 ;
      die($json->encode($results));
    }
    
    // 0 未开始 ， 1 进行中 ， 2 已结束未处理 ， 3 已结束已处理 , 9 延时中

    //----
    $sql = "SELECT a.act_id,a.act_name,a.act_desc,a.is_finished,a.ext_info,a.event_id".
       ", b.brand_id, b.brand_name, b.brand_logo".
       ", g.goods_id,g.goods_name, g.cat_id , g.goods_img,g.original_img,g.goods_desc,g.add_time,g.last_update,g.click_count " .
       " FROM " . $GLOBALS['ecs']->table('goods_activity') . " AS a " .
       " LEFT JOIN " . $GLOBALS['ecs']->table('goods') . " AS g ON a.goods_id = g.goods_id " .
       ' LEFT JOIN ' . $GLOBALS['ecs']->table('brand') . ' AS b ON g.brand_id = b.brand_id ' .
       " WHERE a.act_type = '" . GAT_AUCTION . "' " .
       " AND a.act_id=".$auc_id." AND a.is_show = 1";        
    $auction_row = $GLOBALS['db']->getRow($sql);
    $auction_row['act_name'] = $auction_row['goods_name'] ;//20150629 wangfeng
    if( empty($auction_row) )
    {
      $results['error'] = '拍品无效' ;
      $results['state'] = 11 ;
      die($json->encode($results));
    }

    $tauction = wft_auction_info($auc_id , $user_id ) ;

    //
    $results['act_id'] = $auction_row['act_id'] ;
    $results['act_name'] = $auction_row['act_name'] ;
    $results['act_desc'] = $auction_row['act_desc'] ;
    $results['is_finished'] =     $tauction['is_finished'] ;
    $results['event_id'] = $auction_row['event_id'] ;
    $results['start_time'] =      $tauction['start_time'] ;
    $results['end_time'] =        $tauction['end_time'] ;
    $results['brand_id'] = $auction_row['brand_id'] ;
    if( empty($results['brand_id']) )
        $results['brand_id'] = '0' ;
    $results['brand_name'] = $auction_row['brand_name'] ;
    $results['brand_logo'] = $auction_row['brand_logo'] ;
    $results['goods_id'] = $auction_row['goods_id'] ;
    $results['goods_name'] = $auction_row['goods_name'] ;
    $results['cat_id'] = $auction_row['cat_id'] ;
    $results['goods_img'] = $auction_row['goods_img'] ;
    $results['original_img'] = $auction_row['original_img'] ;
    $results['goods_desc'] = $auction_row['goods_desc'] ;
    $results['click_count'] = $auction_row['click_count'] ;
    $results['a_status'] = (string)$tauction['status_no'];

    
    $overtime_length = $GLOBALS['_CFG']['auction_overtime_length'];//300
    if( empty($overtime_length) ) $overtime_length = 300 ;//20150701 wangfeng
    //v2.2 采用延时
    $tauction['yanshi'] = '0' ;
    $now = time();
    $latest_bid = $tauction['last_bid'];
    $lastest_bid_time = $latest_bid['bid_time'];
    $auction_end_time = $tauction['end_time'];
    $event_info = get_event_info($tauction['event_id']); // lib_common.php
    if (empty($event_info['disable_overtime'])) {
      if($lastest_bid_time + $overtime_length > $auction_end_time) {
        $overtime_count = ceil(($lastest_bid_time + $overtime_length - $auction_end_time)/$overtime_length);
        $overtime_end_time = $auction_end_time+$overtime_count*$overtime_length;
        if($overtime_end_time >= $now && $auction_end_time < $now) {
          $tauction['status_no'] = 9;//延时状态
          $tauction['yanshi'] =  $overtime_end_time ; //延时时间 utc 20150629 wangfeng
        }
      }
    }
    $results['a_status'] = (string)$tauction['status_no'] ;
    $results['yanshi'] = $tauction['yanshi'] ;



    //click count ++
    $sql = "UPDATE ".$GLOBALS['ecs']->table('goods')." SET click_count=".
                    ($auction_row['click_count']+1)." WHERE goods_id=".$auction_row['goods_id'] ;
    $GLOBALS['db']->query($sql) ;
    // like count
    $sql = "SELECT COUNT(*) FROM " .$GLOBALS['ecs']->table('collect_goods') .
              " WHERE goods_id =".$auction_row['goods_id'];
    $results['like_count'] = $GLOBALS['db']->getOne($sql) ;

    $ext_info = unserialize($auction_row['ext_info']);
    if( $results['brand_id']==0 )
    {
        $results['brand_name'] = '' ;
        $results['brand_logo'] = '' ;
    }else
    {
        $results['brand_name'] = $auction_row['brand_name'] ;
        $results['brand_logo'] = $auction_row['brand_logo'] ;
        if(empty($results['brand_logo']))
        {
            $results['brand_logo'] ='';
        }else
        {
            $results['brand_logo'] = 'http://www.hihey.com/data/brandlogo/'.$results['brand_logo'];
        }
    }
    $results['price0'] = (string)$ext_info['start_price'];
    $results['price1'] = (string)$ext_info['end_price'];
    $results['deposit'] = (string)$ext_info['deposit'];
    $results['step'] = (string)$ext_info['amplitude'];
    if( empty($ext_info['commission']) || isset($ext_info['commission'])==false )
    {
        $results['comm'] = '0' ;       
    }else
    {
        $results['comm'] = (string)$ext_info['commission'];
    }
    
    if( $tauction['bid_user_count']==0 )
    {
        $results['bid_id'] = '0' ;
    } else
    {
        $results['bid_id'] =    $tauction['last_bid']['log_id'] ;
        $results['bid_price'] = $tauction['last_bid']['bid_price'] ;
        $results['bid_time'] =  $tauction['last_bid']['bid_time'] ;
        $results['user_id'] =   $tauction['last_bid']['user_id'] ;
        $results['user_name'] = $tauction['last_bid']['user_name'] ;
        $results['avatar'] =    $tauction['last_bid']['avatar'] ;
    }

    $tempSize = get_imagesize_wf2(get_image_path($auction_row['goods_id'], $auction_row['goods_img'], true)) ;
    $results['imgwid'] = (string)$tempSize['w'] ;
    $results['imghei'] = (string)$tempSize['h'] ;

    $results['goods_img'] = 'http://www.hihey.com/'.get_image_path($auction_row['goods_id'], $auction_row['goods_img'], true);
    $results['original_img'] = 'http://www.hihey.com/'.get_image_path($auction_row['goods_id'], $auction_row['original_img'], true);
    //thumb2
    $results['thumb2'] =wft_get_thumb2($results['goods_id']) ;
    if( empty($results['thumb2']) )
    {
        $results['thumb2']=$results['goods_img'] ;
    }

    //goods attributes
    $results['material'] = '' ;
    $results['size'] = '' ;
    $results['year'] = '' ;
    $results['condition'] = '' ;
    $results['version'] = '' ;
    $results['marking'] = '' ;

    $properties = get_art_properties_wf($auction_row['goods_id']);
    foreach ($properties as $p1 )
    {
        if( $p1['name']=='材料' )
          $results['material'] = $p1['value'] ;
        else if( $p1['name']=='尺寸' )
          $results['size'] = $p1['value'] ;
        else if( $p1['name']=='年代' )
          $results['year'] = $p1['value'] ;
        else if( $p1['name']=='品相' )
          $results['condition'] = $p1['value'] ;
        else if( $p1['name']=='版本' )
          $results['version'] = $p1['value'] ;
        else if( $p1['name']=='签名' )
          $results['marking'] = $p1['value'] ;
    }
    //====

    // goods is liked? or in user collection.
    if( $user_id==0 )
        $results['liked']='0' ;
    else
    {
        $sql = "SELECT COUNT(*) FROM " .$GLOBALS['ecs']->table('collect_goods') .
              " WHERE user_id='$user_id' AND goods_id =".$results['goods_id'];
      if ($GLOBALS['db']->getOne($sql) > 0)
      {
        $results['liked']='1' ;
      }else
      {
        $results['liked']='0' ;
      }
    }//goods liked end.


    //order_count
    $results['a_order'] = (string)$tauction['order_count'] ;
    //order_count end

    //user level
    $sql = 'SELECT user_rank FROM '.$GLOBALS['ecs']->table('events')." WHERE event_id = ".$results['event_id'] ;//events 表示拍卖活动表
    $tevent = $GLOBALS['db']->getRow($sql);
    $results['user_rank'] = $tevent['user_rank'] ;

    //url
    $results['url'] = 'http://www.hihey.com/'.build_uri('goods', 
        array('gid' => $results['goods_id']), $results['goods_name']);

    // is watching
    $results['watching'] =   wft_watch_on($user_id,2,$auc_id )?'1':'0' ;
    
    $results['error'] = '' ;
    $results['state'] = 100 ;
    exit($json->encode($results));
}
elseif ($action == 'my')
{
    if( wft_user_ok($user_id, $user_pass)==false )
    {
          $results['error'] = '用户名或密码错误，请重新登录。' ;
          $results['state'] = 2 ;
          die($json->encode($results));
    }
    $sql = '' ;
    if($auc_state==0)
    {
        //我参与的拍品
        $sql = "SELECT COUNT(distinct log.act_id) FROM ".
        $GLOBALS['ecs']->table('auction_log') ." AS log ,".
        $GLOBALS['ecs']->table('goods_activity') ." AS a ,".
        $GLOBALS['ecs']->table('events') ." AS e ".
                " WHERE log.act_id=a.act_id AND a.event_id=e.event_id ".
                " AND a.is_show=1 AND e.is_show=1 ".
                " AND bid_user=".$user_id ;
        $cnt = $GLOBALS['db']->getOne($sql) ;
        $results['count'] = $cnt ;
        if( $cnt ==0 )
        {
            $results['data'] = array() ;
            $results['error'] = '' ;
            $results['state'] = 100 ;
            exit($json->encode($results));  
        }
        $sql = "SELECT log.log_id,log.bid_price,log.bid_time ".
           " ,a.act_id,a.act_name,a.is_finished,a.is_show,a.ext_info ".
           " ,e.event_id,e.event_name,e.start_time,e.end_time,e.is_show,e.disable_overtime ".
           " ,g.goods_id,g.goods_img,g.goods_name ".//20150629 wangfeng
           " ,b.brand_id,b.brand_name,b.brand_logo ".
           "  ".
           " FROM ".$GLOBALS['ecs']->table('auction_log')." AS log ".
           " LEFT JOIN ".$GLOBALS['ecs']->table('goods_activity')." AS a ".
           " ON log.act_id=a.act_id ".
           " LEFT JOIN ".$GLOBALS['ecs']->table('events')." AS e ".
           " ON a.event_id=e.event_id ".
           " LEFT JOIN ".$GLOBALS['ecs']->table('goods')." AS g ".
           " ON a.goods_id=g.goods_id ".
           " LEFT JOIN ".$GLOBALS['ecs']->table('brand')." AS b ".
           " ON g.brand_id=b.brand_id ".
           "   ".
           "   ".
            " WHERE a.is_show=1 AND e.is_show=1 AND log.bid_user=".$user_id.
            " GROUP BY log.act_id ORDER BY log.log_id DESC " ;

    }else if( $auc_state==1 )
    {//竞价成功的拍品
        
        $sql = "SELECT count(my.maxlog1) ".
                " from ".
                " (select max(log_id) as maxlog1 ,act_id ".
                " from ".$GLOBALS['ecs']->table('auction_log').
                " where bid_user=".$user_id.
                " group by act_id) as my".
                " inner join (select act_id,max(log_id) as maxlog2 ".
                " from ".$GLOBALS['ecs']->table('auction_log').
                " Group by act_id ) as ml ON ".
                " my.act_id=ml.act_id ".",".
                $GLOBALS['ecs']->table('goods_activity') ." AS a ,".
                $GLOBALS['ecs']->table('events') ." AS e ".
                " WHERE my.act_id = a.act_id AND a.event_id=e.event_id ".
                " AND a.is_show=1 AND e.is_show=1 ". 
                " AND my.maxlog1=ml.maxlog2 " ;
        $cnt1 = $GLOBALS['db']->getOne($sql) ;
        $results['count'] = $cnt1 ;
        if( $cnt1==0 )
        {
            $results['data'] = array() ;
            $results['error'] = '' ;
            $results['state'] = 100 ;
            exit($json->encode($results));  
        }
        $gmnow = gmtime() ;
        $sql = "select my.maxlog1,my.act_id ".
               " ,log.log_id,log.bid_price,log.bid_time ".
               " ,a.act_id,a.act_name,a.is_finished,a.is_show,a.ext_info ".
               " ,e.event_id,e.event_name,e.start_time,e.end_time,e.is_show,e.disable_overtime ".
               " ,g.goods_id,g.goods_img,g.goods_name ".//20150629 wangfeng
               " ,b.brand_id,b.brand_name,b.brand_logo ".
               "   ".
                " from ".
                " (select max(log_id) as maxlog1 ,act_id ".
                " from ".$GLOBALS['ecs']->table('auction_log').
                " where bid_user=".$user_id.
                " group by act_id) as my".
                " inner join (select act_id,max(log_id) as maxlog2 ".
                " from ".$GLOBALS['ecs']->table('auction_log').
                " Group by act_id ) as ml ON ".
                " my.act_id=ml.act_id ". 
                " LEFT JOIN ".$GLOBALS['ecs']->table('auction_log')." AS log ".
                " ON log.log_id=my.maxlog1 ".
                " LEFT JOIN ".$GLOBALS['ecs']->table('goods_activity')." AS a ".
                " ON log.act_id=a.act_id AND a.is_show=1 ".
                " LEFT JOIN ".$GLOBALS['ecs']->table('events')." AS e ".
                " ON a.event_id=e.event_id AND e.is_show=1 ".
                "  ".
                " , ".$GLOBALS['ecs']->table('goods')." AS g ".
                " LEFT JOIN ".$GLOBALS['ecs']->table('brand')." AS b ".
                " ON b.brand_id=g.brand_id ".
                " WHERE my.maxlog1=ml.maxlog2 AND g.goods_id=a.goods_id ".
                " AND e.end_time<".$gmnow  ;
        $sql = $sql." order by log.bid_time DESC " ;
    }else if( $auc_state==2 )
    {//关注的拍品

        $cnt2 = 0;
        $results['count'] = '0' ;
        if( $cnt2 == 0 )
        {
            $results['data'] = array() ;
            $results['error'] = '' ;
            $results['state'] = 100 ;
            exit($json->encode($results)); 
        }
        
    }
    else 
    {
        $results['data'] = array() ;
        $results['error'] = 'auc_state undefined.' ;
        $results['state'] = 1 ;
        die($json->encode($results));  
    }

    $res = $GLOBALS['db']->selectLimit($sql, $page_size, ($page - 1) * $page_size);
    $gmnow = gmtime() ;
    while ($row = $GLOBALS['db']->fetchRow($res))
    {
        $row['act_name'] = $row['goods_name'] ;//20150629 wangfeng
        if( empty($row['brand_id']) )
        {
            $row['brand_id'] = '0' ;
            $row['brand_name'] = '' ;
            $row['brand_logo'] = '' ;
        }else
        {
            $row['brand_logo'] = 'http://www.hihey.com/data/brandlogo/'.$row['brand_logo'] ;
        }

        if( empty($row['notification_id']) )
        {
            $row['notification_id'] = '0' ;
            $row['valid'] = '0' ;
        }
        if( $row['notification_id'] > 0 && $row['valid']==1 )
        {
            $row['watching'] = '1' ;
        }else
        {
            $row['watching'] = '0' ;
        }

        $row['goods_img'] = 'http://www.hihey.com/'.$row['goods_img'] ;
        //latest bid
        $sql = "SELECT log.*,u.user_name,u.avatar,u.show_data FROM ".
                $GLOBALS['ecs']->table('auction_log')." AS log, ".
                $GLOBALS['ecs']->table('users')." AS u ".
                " WHERE log.act_id=".$row['act_id'].
                " AND log.bid_user=u.user_id  ORDER BY log_id DESC" ;
        $latest_bid = $GLOBALS['db']->getRow($sql) ;
        $row['last_log_id'] = empty($latest_bid['log_id'])?'0':$latest_bid['log_id'] ;
        $row['last_user_id'] =empty($latest_bid['bid_user'])?'0':$latest_bid['bid_user'] ; 
        if( $latest_bid['show_data']==0 )
        {
            $row['last_user_name'] ='匿名收藏家';
            $row['last_user_avatar'] = wft_avatarByUserId($row['last_user_id']) ;
        }else
        {
            $row['last_user_name'] =$latest_bid['user_name'];
            if( empty($latest_bid['avatar']) )
                $row['last_user_avatar'] = wft_avatarByUserId($row['last_user_id']) ;
            else  $row['last_user_avatar'] = 'http://www.hihey.com/'.$latest_bid['avatar'] ;
        }

        $row['last_bid_price'] = empty($latest_bid['bid_price'])?'0':$latest_bid['bid_price'] ; 
        $row['last_bid_time'] = $latest_bid['bid_time'] ;

        //auction state.
        if ($row['is_finished'] == 0)
        {
          if ($gmnow < $row['start_time'])
            $row['status_no']= 0 ; // 未开始 0
          elseif ($gmnow > $row['end_time']) 
            $row['status_no']= 2; // 已结束，未处理 2
          else 
            $row['status_no']= 1; // 进行中 1
        }
        elseif ($row['is_finished'] == 1)
            $row['status_no']= 2; // 已结束，未处理 2
        else
            $row['status_no']= 3; // 已结束，已处理 3

        if ($row['status_no'] > 1)
        {
          $sql = "SELECT COUNT(*)" .
                  " FROM " . $GLOBALS['ecs']->table('order_info') .
                  " WHERE extension_code = 'auction'" .
                  " AND extension_id=".$row['act_id'] .
                  " AND order_status " . db_create_in(array(OS_CONFIRMED, OS_UNCONFIRMED, OS_SPLITED, OS_SPLITING_PART));
          $row['order_count'] = $GLOBALS['db']->getOne($sql);
        }
        else
        {
          $row['order_count'] = '0';
        }

        //延时 v2.2 20150629 wangfeng
        $row['yanshi'] = '0' ;//20150629 wangfeng
        $overtime_length = $GLOBALS['_CFG']['auction_overtime_length'];//300
        if( empty($overtime_length) ) $overtime_length = 300 ;//20150701 wangfeng
        $lastest_bid_time = $latest_bid['bid_time'];
        $auction_end_time = $row['end_time'];
        if ( $row['disable_overtime'] == 0 )
        {
          if($lastest_bid_time + $overtime_length > $auction_end_time) {
            $overtime_count = ceil(($lastest_bid_time + $overtime_length - $auction_end_time)/$overtime_length);
            $overtime_end_time = $auction_end_time+$overtime_count*$overtime_length;
            if($overtime_end_time >= $gmnow && $auction_end_time < $gmnow) {
              $row['status_no'] = 9 ;//延时状态
              $row['yanshi'] = (string)wft_gmt2utc( $overtime_end_time ); //延时时间 utc 20150629 wangfeng
            }
          }
        }
        //utc.
        $row['start_time'] = (string)($row['start_time']+28800 ) ;
        $row['end_time'] = (string)($row['end_time']+28800  ) ;
        $row['bid_time'] = (string)($row['bid_time']+28800  )  ;
        $row['last_bid_time']= (string)($row['last_bid_time']+28800  ) ;

        $row['status_no']= (string)$row['status_no'] ;

        //ext_info
        $ext_info = unserialize($row['ext_info']);
        $row['step'] = (string)$ext_info['amplitude'];
        unset($row['ext_info']) ;

        $data[] = $row ;
    }
    $results['data'] = $data ;
    $results['error'] = '' ;
    $results['state'] = 100 ;
    exit($json->encode($results));


}
elseif ($action == 'cancel')
{
    include_once(ROOT_PATH2 . 'includes/lib_order.php');
    if( wft_user_ok($user_id, $user_pass)==false )
    {
      $results['error'] = '用户名或密码错误，请重新登录。' ;
      $results['state'] = 2 ;
      die($json->encode($results));
    }
    $userinfo = user_info_wf($user_id) ;
    $rank = $userinfo['user_rank'] ;

    if( $auc_id <= 0 )
    {// 取得参数：拍卖活动id 
      $results['error'] = '拍品ID未指定' ;
      $results['state'] = 10 ;
      die($json->encode($results));
    }

    // 取得拍卖活动信息 
    $auction = wft_auction_info($auc_id , $user_id );
    if (empty($auction))
    {
        $results['error'] = '获取拍卖活动信息失败' ;
        $results['state'] = 11 ;
        die($json->encode($results));
    }
    
    if ($auction['is_finished'] == 8)
    {
        $results['error'] = '拍品订单已取消' ;
        $results['state'] = 14 ;
        die($json->encode($results));
    }

    // 查询：有人出价吗 
    if ($auction['bid_user_count'] <= 0)
    {
        $results['error'] = '拍品无人出价' ;
        $results['state'] = 16 ;
        die($json->encode($results));
    }

    // 查询：是否已经有订单
    if ($auction['order_count'] > 0)
    {
        $results['error'] = '拍品已经形成订单' ;
        $results['state'] = 18 ;
        die($json->encode($results));
    }

    // 查询：最后出价的是该用户吗 
    if ($auction['last_bid']['bid_user'] != $user_id)
    {
        $results['error'] = '您不是最后出价用户' ;
        $results['state'] = 20 ;
        die($json->encode($results));
    }
    $sql = "UPDATE ". $GLOBALS['ecs']->table('goods_activity') ." SET is_finished='8' WHERE act_id=".$auc_id;
    $GLOBALS['db']->query($sql);
    log_account_change($user_id, 0, (-1) * $auction['deposit'], 0, 0, sprintf($_LANG['deduct_auction_deposit_cancel'], $auction['act_name']));

    $results['info'] = '由于您取消拍品购买,扣除保证金'.$auction['deposit'].'元' ;
    $results['error'] = '' ;
    $results['state'] = 100 ;
    exit($json->encode($results));

}elseif ($action == 'bidlist')
{
    if( $auc_id <= 0 )
    {// 取得参数：拍卖活动id 
      $results['error'] = '拍品ID未指定' ;
      $results['state'] = 10 ;
      die($json->encode($results));
    }
    $results['page'] = $page ;
    $results['page_size'] = $page_size ;

    $sql = "SELECT a.log_id, a.bid_price , a.bid_time , " .
           " u.user_id , u.user_name , u.avatar , u.show_data ".
           " FROM " . $GLOBALS['ecs']->table('auction_log') . " AS a, ".
           $GLOBALS['ecs']->table('users') . " AS u ".
           " WHERE a.act_id = ".$auc_id." AND a.bid_user=u.user_id ".
           " ORDER BY a.log_id DESC ";
    $res = $GLOBALS['db']->selectLimit($sql, $page_size, ($page - 1) * $page_size);
    $data = array() ;
    while ($row = $GLOBALS['db']->fetchRow($res))
    {
        if( $row['show_data']==0 )
        {
            $row['user_name'] = '匿名收藏家' ;
            $row['avatar'] = wft_avatarByUserId($row['user_id']) ;
        }else
        {
            if( empty($row['avatar']) )
            {
                $row['avatar'] = wft_avatarByUserId($row['user_id']) ;
            }else
            {
                $row['avatar'] = 'http://www.hihey.com/'.$row['avatar'] ;
            }
        }
        $row['bid_time'] = (string)($row['bid_time']+28800 ) ;
        $data[] = $row ;
    }

    $sql = "SELECT COUNT(*) " .
            " FROM " . $GLOBALS['ecs']->table('auction_log') .
            " WHERE act_id = '$auc_id' ";    
    $results['count'] = (string)$GLOBALS['db']->getOne($sql) ;
    $results['data'] = $data ;
    $results['error'] = '' ;
    $results['state'] = 100 ;
    exit($json->encode($results));
}


$results['error'] = '未定义操作' ;
$results['state'] = 3 ;
exit($json->encode($results));


//=========================================================

    
//===================================================           
function user_info_wf($userid)
{
    $sql = "SELECT *".
        " FROM " . $GLOBALS['ecs']->table('users') .
        " WHERE user_id = '$userid'";
    $arr = $GLOBALS['db']->getRow($sql);
    return $arr ;
}

//=================================================



//获得指定作品属性  
function get_art_properties_wf($goods_id) {
  $sql = "SELECT a.attr_id, a.attr_name, a.attr_name_en_us, a.attr_group, a.is_linked, a.attr_type, ".
    "g.goods_attr_id, g.attr_value, g.attr_price " .
    'FROM ' . $GLOBALS['ecs']->table('goods_attr') . ' AS g ' .
    'LEFT JOIN ' . $GLOBALS['ecs']->table('attribute') . ' AS a ON a.attr_id = g.attr_id ' .
    "WHERE g.goods_id = '$goods_id' " .
    'ORDER BY a.sort_order, g.attr_price, g.goods_attr_id';
  $res = $GLOBALS['db']->getAll($sql);

  $properties = array();
  foreach ($res AS $row) {
    $row['attr_value'] = str_replace("\n", '<br />', $row['attr_value']);
    if ($row['attr_type'] == 0) {
      $properties[$row['attr_id']]['name']  = siy_ml_switch($row['attr_name'],$row['attr_name_'.$GLOBALS['_CFG']['lang']]);
      $properties[$row['attr_id']]['value'] = siy_ml_switch($row['attr_value']);
    }
  }
  return $properties;
}

//=======================================


function get_imagesize_wf2($filepathInDb)
{
  $w = -1 ;
  $h = -1 ;
  if (file_exists('../'.$filepathInDb)) 
  {
    $arr_img = getimagesize('../'.$filepathInDb) ;
    if( $arr_img !== false )
    {
      $w = $arr_img[0] ;
      $h = $arr_img[1] ;
    }
  }
  $sz['w'] = $w ;
  $sz['h'] = $h ;
  return $sz ;
}


?>

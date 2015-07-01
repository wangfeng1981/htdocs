<?php
/* 拍卖列表API */
define('IN_ECS', true);
require('./init.php');
require_once('./wftools.php');
require_once(ROOT_PATH . 'includes/cls_json.php');
header("Content-type: application/json; charset=UTF-8"); 

$json = new JSON;
$results = array() ;
$results['error'] = '' ;
$results['state'] = 0 ;


$page = 1;
$user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']):0;
$eid = isset($_REQUEST['id']) ? intval($_REQUEST['id']):0;
$size = isset($_REQUEST['page_size']) && intval($_REQUEST['page_size']) > 0 ? intval($_REQUEST['page_size']) : 10;
$page = isset($_REQUEST['page']) && intval($_REQUEST['page']) > 0 ? intval($_REQUEST['page']) : 1;
$mode = isset($_REQUEST['mode']) ? intval($_REQUEST['mode']) : 0; //mode=0 short ;mode=1 full
$results['page'] = (string)$page;
$results['page_size'] = (string)$size ;
$results['mode'] = $mode ;

if($eid<=0 )
{
    $results['error'] = '拍卖活动ID无效' ;
    $results['state'] = 1 ;
    die( $json->encode($results) ) ;
}


$sql = 'SELECT event_id,event_name,start_time,end_time,image,disable_overtime '.
        ' FROM '.$GLOBALS['ecs']->table('events').
        " WHERE is_show=1 AND event_id = ".$eid;
$event = $GLOBALS['db']->getRow($sql);
$event['start_time']=($event['start_time']+28800 )  ;//utc
$event['end_time']=($event['end_time']+28800 ) ;//utc
$start_time = $event['start_time']  ;
$end_time = $event['end_time']  ;
if( empty($event) )
{
    $results['error'] = '拍卖活动已失效' ;
    $results['state'] = 2 ;
    die( $json->encode($results) ) ;
}

$results['event_id'] = $event['event_id'] ;
if($mode==1)
{
    $results['event_name'] = $event['event_name'] ;
    $results['event_image'] = 'http://www.hihey.com/data/event/'.$event['image'] ;
}

$sql = "SELECT a.act_id,a.act_name,a.act_desc,a.is_finished,a.ext_info".
       ", b.brand_id, b.brand_name, b.brand_logo".
       ", g.goods_id, g.cat_id , g.goods_img , g.goods_name " .//20150629 wangfeng
       " FROM " . $GLOBALS['ecs']->table('goods_activity') . " AS a " .
       " LEFT JOIN " . $GLOBALS['ecs']->table('goods') . " AS g ON a.goods_id = g.goods_id " .
       ' LEFT JOIN ' . $GLOBALS['ecs']->table('brand') . ' AS b ON g.brand_id = b.brand_id ' .
       " WHERE a.act_type = '" . GAT_AUCTION . "' " .
       " AND a.event_id=".$eid." AND a.is_show = 1 ORDER BY a.act_id DESC";        
$res = $GLOBALS['db']->selectLimit($sql, $size, ($page - 1) * $size);
$data = array() ;
$shortdata = array() ;
$overtime_length = $GLOBALS['_CFG']['auction_overtime_length'];//300
if( empty($overtime_length) ) $overtime_length = 300 ; //20150701 wangfeng
$disable_overtime = $event['disable_overtime'] ;
$tsnow = time()  ;

while ($row = $GLOBALS['db']->fetchRow($res))
{
    $ext_info = unserialize($row['ext_info']);
    $row['act_name'] = $row['goods_name'] ; //20150629 wangfeng
    unset($row['ext_info']) ;
    if( $row['brand_id']==0 )
    {
        $row['brand_name'] = '' ;
        $row['brand_logo'] = '' ;
    }else
    {
        if(!empty($row['brand_logo']))
        {
            $row['brand_logo'] = 'http://www.hihey.com/data/brandlogo/'.$row['brand_logo'];
        }
    }
    $row['price0'] = (string)$ext_info['start_price'];
    $row['price1'] = (string)$ext_info['end_price'];
    $row['deposit'] = (string)$ext_info['deposit'];
    $row['step'] = (string)$ext_info['amplitude'];
    $sql1 = "SELECT a.log_id,a.bid_price,a.bid_time,u.user_id,u.user_name, u.avatar, u.show_data " .
        " FROM " . $GLOBALS['ecs']->table('auction_log') . " AS a," .
                  $GLOBALS['ecs']->table('users') . " AS u " .
        " WHERE a.bid_user = u.user_id " .
        " AND a.act_id =".$row['act_id']. 
        " ORDER BY a.bid_time DESC";
    $row1 = $GLOBALS['db']->getRow($sql1);
    if( empty($row1) )
    {
        $row['bid_id'] = '0' ;
        $row['bid_time'] = '0' ;
    } else
    {
        $row['bid_id'] = $row1['log_id'] ;
        $row['bid_price'] = $row1['bid_price'] ;
        $row['bid_time'] = (string)($row1['bid_time']+28800  ) ;
        $row['user_id'] = $row1['user_id'] ;
        if($row1['show_data']==1)
        {
            $row['user_name'] = $row1['user_name'] ;
            $row['avatar'] = $row1['avatar'] ;
            if( empty($row['avatar']) )
                $row['avatar'] = wft_avatarByUserId( $row['user_id'] ); 
            else
                $row['avatar'] ='http://www.hihey.com/'.$row['avatar'] ;

        }else
        {
            $row['user_name'] = '匿名收藏家' ;
            $row['avatar'] = wft_avatarByUserId( $row['user_id'] ); 
        }
    }

    $row['status'] = auction_status2($tsnow, $row['is_finished'] , $start_time , $end_time ) ;
    $row['yanshi'] = '0' ;//20150629 wangfeng
    //是否延时 bid_time is utc
    if( $overtime_length>0 && $disable_overtime==0 && intval($row['bid_time']) > 0 )
    {
        if( $row['bid_time'] + $overtime_length > $end_time ) //end_time utc
        {
            $overtime_count = ceil(( $row['bid_time'] + $overtime_length - $end_time )/$overtime_length);
            $overtime_end_time = $end_time + $overtime_count*$overtime_length;
            if($overtime_end_time >= $tsnow && $end_time < $tsnow ) 
            {
                $row['status'] = 9 ;//延时状态
                $row['yanshi'] = (string)wft_gmt2utc( $overtime_end_time ); //延时时间 utc 20150629 wangfeng
            }
        }
    } 
    $row['status'] = (string)$row['status'] ;

    $tempSize = get_imagesize_wf(get_image_path($row['goods_id'], $row['goods_img'], true)) ;
    $row['imgwid'] = (string)$tempSize['w'] ;
    $row['imghei'] = (string)$tempSize['h'] ;
    
    //order_count
    $sql = "SELECT COUNT(*)" .
              " FROM " . $GLOBALS['ecs']->table('order_info') .
              " WHERE extension_code = 'auction'" .
              " AND extension_id =" .$row['act_id'].
              " AND order_status ".  
              db_create_in(array(OS_CONFIRMED, OS_UNCONFIRMED, OS_SPLITED, OS_SPLITING_PART));
    $auction_order = $GLOBALS['db']->getOne($sql);
    $row['a_order'] = $auction_order ;
    //order_count end

    $row['goods_img'] = $GLOBALS['su'].get_image_path($row['goods_id'], $row['goods_img'], true);
    if( $tempSize['h']/$tempSize['w'] > 2 )
    {
        $thumb2 = get_thumb2_wf($row['goods_id']) ;
        if( !empty($thumb2))
            $row['goods_img'] = $thumb2 ;
    }

    // is watching
    $row['watching'] =  wft_watch_on($user_id,2,$row['act_id'] )?'1':'0' ;

    $data[] = $row ;

    //short
    $short['act_id'] = $row['act_id'] ;
    $short['status'] = $row['status'] ;
    $short['bid_id'] = $row['bid_id'] ;
    $short['watching'] = $row['watching'] ;
    $short['bid_price'] = '0' ;
    $short['bid_time'] = '0' ;
    $short['user_id'] = '0' ;
    $short['user_name'] = '' ;
    $short['avatar'] = '' ;
    $short['a_order'] = '0' ;
    if($short['bid_id']>0)
    {
        $short['bid_price'] = $row['bid_price'] ;
        $short['bid_time'] = $row['bid_time'] ;
        $short['user_id'] = $row['user_id'] ;
        $short['user_name'] = $row['user_name'] ;
        $short['avatar'] = $row['avatar'] ;
        $short['a_order'] = $auction_order ;
        $short['yanshi'] = $row['yanshi'] ;//20150629 wangfeng
    } 
    $shortdata[] = $short ;
}

$total = 0;
$sql = "SELECT act_id FROM " . $GLOBALS['ecs']->table('goods_activity') .
       " WHERE act_type =2  AND is_show=1 AND event_id=".$eid ;

$rows = $GLOBALS['db']->getAll($sql);
$num_yanshi = 0 ;
$end_yanshi = 0 ;
foreach ($rows AS $one) 
{
    $eaid = $one['act_id'] ;
    $sql1 = 'SELECT bid_price,bid_time FROM '.$GLOBALS['ecs']->table('auction_log').' WHERE act_id = '.$eaid.' ORDER BY bid_time DESC';
    $row1 = $GLOBALS['db']->getRow($sql1) ;
    $total += $row1['bid_price'] ;
    
    $row1['bid_time'] += 28800 ;//utc
    //是否延时 bid_time is utc
    if( $overtime_length>0 && $disable_overtime==0 && intval($row1['bid_time']) > 0 )
    {
        if( $row1['bid_time'] + $overtime_length > $end_time ) //end_time utc
        {
            $overtime_count = ceil(( $row1['bid_time'] + $overtime_length - $end_time )/$overtime_length);
            $overtime_end_time = $end_time + $overtime_count*$overtime_length;
            if($overtime_end_time >= $tsnow && $end_time < $tsnow ) 
            {
                $num_yanshi++ ;
                if( $end_yanshi < $overtime_end_time )
                {
                    $end_yanshi = $overtime_end_time ;
                }
            }
        }
    }

}

$count = auction_count($eid);
$results['count'] = (string)$count ;
$results['all_price'] = (string)$total ;
$results['start_time'] = $event['start_time'] ;
$results['end_time'] = $event['end_time'] ;
$results['num_yanshi'] = (string)$num_yanshi ;
$results['end_yanshi'] = (string)$end_yanshi ;
if( $mode==1 )
    $results['data'] = $data ;
else
    $results['data'] = $shortdata ;
$results['state'] = (string)100 ;
exit( $json->encode($results) );



function auction_count($eid1)
{
    $sql = "SELECT COUNT(*) " .
            "FROM " . $GLOBALS['ecs']->table('goods_activity') .
            "WHERE act_type = '" . GAT_AUCTION . "' " .
            "AND event_id=".$eid1."  AND is_show = 1";        
    return $GLOBALS['db']->getOne($sql);
}



function auction_status2($now, $finished, $stime , $etime )
{
    if ( $finished == 0)
    {
        if ($now < $stime )
        {
            return PRE_START; // 未开始 0
        }
        elseif ($now > $etime )
        {
            return FINISHED; // 已结束，未处理 2
        }
        else
        {
            return UNDER_WAY; // 进行中 1
        }
    }
    elseif ( $finished == 1)
    {
        return FINISHED; // 已结束，未处理 2
    }
    else
    {
        return SETTLED; // 已结束，已处理
    }
}

function get_thumb2_wf($goods_id) {
  $sql = 'SELECT thumb2 ' .//david
    ' FROM ' . $GLOBALS['ecs']->table('goods_gallery') .
    " WHERE goods_id = '$goods_id' LIMIT 1 " ;
  $row = $GLOBALS['db']->getRow($sql);
  if( empty($row) )
    return '' ;
  else
    return  $GLOBALS['su'].$row['thumb2'] ;
}
?>

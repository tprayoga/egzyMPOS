<?php $grtotal=0;
$totalitem=0;
 $calvat=0;
$discount=0;
$itemtotal=0;
 $pvat=0;
 $multiplletax = array();
  $this->load->model('ordermanage/order_model',	'ordermodel');
if ($cart = $this->cart->contents()){?>
<table class="table table-bordered" border="1" width="100%" id="addinvoice">
    <thead>
        <tr>
            <th><?php echo display('item')?></th>
            <th><?php echo display('varient_name')?></th>
            <th><?php echo display('price');?></th>
            <th><?php echo display('qty');?></th>
            <th><?php echo display('total');?></th>
            <th><?php echo display('action');?></th>
        </tr>
    </thead>
    <tbody class="itemNumber">
        <?php $i=0; 
								      $totalamount=0;
									  $subtotal=0;
									 $pvat=0;
									 $discount=0;
									 $pdiscount=0;
									foreach ($cart as $item){
										$iteminfo=$this->ordermodel->getiteminfo($item['pid']);
									
										$itemprice= $item['price']*$item['qty'];
										$mypdiscountprice =0;
										if(!empty($taxinfos)){
                                    $tx=0;
                                    if($iteminfo->OffersRate>0){
                                        $mypdiscountprice=$iteminfo->OffersRate*$itemprice/100;
                                      }
                                      $itemvalprice =  ($itemprice-$mypdiscountprice);
                                    foreach ($taxinfos as $taxinfo) 
                                    {
                                      $fildname='tax'.$tx;
                                      if(!empty($iteminfo->$fildname)){
                                      $vatcalc=$itemvalprice*$iteminfo->$fildname/100;
                                       $multiplletax[$fildname] = $multiplletax[$fildname]+$vatcalc;
                                      }
                                      else{
                                        $vatcalc=$itemvalprice*$taxinfo['default_value']/100; 
                                         $multiplletax[$fildname] = $multiplletax[$fildname]+$vatcalc; 

                                      }

                                    $pvat=$pvat+$vatcalc;
                                    $vatcalc =0; 
                                    $tx++;  
                                    }
                                  }
										 else{
										  $vatcalc=$itemprice*$iteminfo->productvat/100;
										  $pvat=$pvat+$vatcalc;
										  }
										
										if($iteminfo->OffersRate>0){
											$mypdiscount=$iteminfo->OffersRate*$itemprice/100;
											$pdiscount=$pdiscount+($iteminfo->OffersRate*$itemprice/100);
											}
										else{
											$mypdiscount=0;
											$pdiscount=$pdiscount+0;
											}
										if(!empty($item['addonsid'])){
											$nittotal=$item['addontpr'];
											$itemprice=$itemprice+$item['addontpr'];
											}
										else{
											$nittotal=0;
											$itemprice=$itemprice;
											}
										 $totalamount=$totalamount+$nittotal;
										$subtotal=$subtotal+$nittotal+$item['price']*$item['qty'];
									$i++;
									$totalitem=$i;
									?>
        <tr id="<?php echo $i;?>">
            <th id="product_name_MFU4E"><?php echo  $item['name'];
											 echo "<br>";
											if(!empty($item['addonsid'])){
											echo '<span style="font-weight: normal;">'.$item['addonname'].'</span>';
											if(!empty($taxinfos)){
                                        
                                         $addonsarray = explode(',',$item['addonsid']);
                                         $addonsqtyarray = explode(',',$item['addonsqty']);
                                         $getaddonsdatas = $this->db->select('*')->from('add_ons')->where_in('add_on_id',$addonsarray)->get()->result_array();
                                         $addn=0;
                                        foreach ($getaddonsdatas as $getaddonsdata) {
                                          $tax=0;
                                        
                                          foreach ($taxinfos as $taxainfo) 
                                          {

                                            $fildaname='tax'.$tax;

                                        if(!empty($getaddonsdata[$fildaname])){
                                            
                                        $avatcalc=($getaddonsdata['price']*$addonsqtyarray[$addn])*$getaddonsdata[$fildaname]/100; 
                                        $multiplletax[$fildaname] = $multiplletax[$fildaname]+$avatcalc;
                                         
                                        }
                                        else{
                                          $avatcalc=($getaddonsdata['price']*$addonsqtyarray[$addn])*$taxainfo['default_value']/100;
                                          $multiplletax[$fildaname] = $multiplletax[$fildaname]+$avatcalc;  
                                        }

                                      $pvat=$pvat+$avatcalc;

                                            $tax++;
                                          }
                                          $addn++;
                                        }
                                        }
											}
											?>
                      
            </th>
            <td>
                <?php echo $item['size'];?>
            </td>

            <td width="">
                <?php echo $item['price'];?>
            </td>
            <td scope="row">
                <a class="btn btn-info btn-sm btnleftalign"
                    onclick="posupdatecart('<?php echo $item['rowid']?>',<?php echo $item['pid']?>,<?php echo $item['sizeid']?>,<?php echo $item['qty'];?>,'add')"><i
                        class="fa fa-plus" aria-hidden="true"></i></a>
                <span id="productionsetting-<?php echo $item['pid'].'-'.$item['sizeid'] ?>"> <?php echo $item['qty'];?>
                </span>
                <a class="btn btn-danger btn-sm btnrightalign"
                    onclick="posupdatecart('<?php echo $item['rowid']?>',<?php echo $item['pid']?>,<?php echo $item['sizeid']?>,<?php echo $item['qty'];?>,'del')"><i
                        class="fa fa-minus" aria-hidden="true"></i></a>
            </td>
            <td width="">
                <?php echo $itemprice-$mypdiscount;?>
            </td>

            <td width:"80"=""><a class="btn btn-danger btn-sm btnrightalign"
                    onclick="removecart('<?php echo $item['rowid'];?>')"><i class="ti-trash" aria-hidden="true"></i></a>
            </td>
        </tr>
        <?php } 
									$itemtotal=$subtotal;
									$grtotal=$itemtotal;
									?>

        <input name="grandtotal" id="grtotal" type="hidden" value="<?php echo $grtotal-$pdiscount;?>" />

    </tbody>
</table>
<?php }  
if(!empty($this->cart->contents())){
	  $totalsercharge=$subtotal-$pdiscount;
	  $servicetotal=$settinginfo->servicecharge*$totalsercharge/100;

  $servicecharge= $settinginfo->servicecharge;
	}
else{
	$servicetotal=0;
	$servicecharge=0;
	}

// PBJT HANYA dari engine PBJT (tarif ketetapan Pemda): bukan dari VAT produk, setting.vat, atau
// modul pajak. DPP = subtotal - diskon + service charge.
require_once APPPATH . 'modules/pbjt/libraries/Pbjt_pajak.php';
$pbjtIncl = !empty($settinginfo->resto_price_includes_tax);
$pbjtCart = Pbjt_pajak::hitung((!empty($subtotal) ? $subtotal : 0) - $pdiscount, $servicetotal, Pbjt_pajak::tarif(), $pbjtIncl);
$calvat = $pbjtIncl ? 0 : $pbjtCart['pbjt'];
$multiplletax = array();
$multiplletaxvalue=htmlentities(serialize($multiplletax));
?>
<input name="subtotal" id="subtotal" type="hidden" value="<?php if(!empty($subtotal)){echo $subtotal;}?>" />
<input name="totalitem" id="totalitem" type="hidden" value="<?php echo $totalitem;?>" />
<input name="multiplletaxvalue" id="multiplletaxvalue" type="hidden" value="<?php echo $multiplletaxvalue;?>" />
<input name="tvat" type="hidden" value="<?php echo $calvat;?>" id="tvat" />
<input name="sc" type="hidden" value="<?php echo $servicecharge;?>" id="sc" />
<input name="tdiscount" type="hidden" value="<?php if(!empty($pdiscount)){echo $pdiscount;}else{$pdiscount = 0; }?>"
    placeholder="0.00" id="tdiscount" />
<input name="tgtotal" type="hidden" value="<?php echo !empty($this->cart->contents()) ? $pbjtCart['total'] : 0;?>" id="tgtotal" />
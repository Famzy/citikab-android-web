<?php

/**
 * Payout Type DataTable
 *
 * @package     Gofer
 * @subpackage  DataTable
 * @category    Payouts 
 * @author      Trioangle Product Team
 * @version     1.7
 * @link        http://trioangle.com
 */

namespace App\DataTables;

use App\Models\Trips;
use Yajra\Datatables\Services\DataTable;
use App\Http\Start\Helpers;
use DB;

class CompanyPayoutDataTable extends DataTable
{
    protected $filter_type;

    // Set the Type of Filter applied to Payout
    public function setFilter($filter_type)
    {
        $this->filter_type = $filter_type;
        return $this;
    }

    /**
     * Display ajax response.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajax()
    {
        $trips = $this->query();

        return $this->datatables
            ->of($trips)
            ->addColumn('company_payout', function ($trips) {
                $payment_pending_trips = Trips::CompanyPayoutTripsOnly()
                ->select('trips.*','users.company_id as company_id','companies.name as company_name')
                ->whereHas('driver',function($q) use ($trips){
                    $q->where('company_id',$trips->company_id);
                });

                if($this->filter_type == 'Weekly') {
                    $date = $trips->week_days;
                    $payment_pending_trips = $payment_pending_trips->whereBetween('trips.created_at', [$date['week_start'], $date['week_end']]);
                }

                $total_payout = $payment_pending_trips->get()->sum('driver_payout');
                return @$trips->currency->symbol.$total_payout;
            })
            ->addColumn('week_day', function ($trips) {
                $date = $trips->week_days;
                return date('d M', strtotime($date['week_start'])) . ' - ' . date('d M', strtotime($date['week_end']));
            })
            ->addColumn('action', function ($trips) {   
                if($this->filter_type == 'OverAll') {
                    $action = '<a href="'.url('admin/weekly_payout/company/'.$trips->company_id).'" class="btn btn-xs btn-primary"><i class="fa fa-eye"></i></a>';
                    $payment_action = '<form action="'.url('admin/make_payout/company').'" method="post" name="payout_form" style="display:inline-block">
                        <input type="hidden" name="type" value="company_overall">
                        <input type="hidden" name="_token" value="'.csrf_token().'">
                        <input type="hidden" name="company_id" value="'.$trips->company_id.'">
                        <input type="hidden" name="redirect_url" value="admin/payout/company/overall">
                        <button type="submit" class="btn btn-primary make-pay-btn" name="submit" value="submit"> Paid </button>
                        
                        </form>';
                }
                else if($this->filter_type == 'Weekly') {
                    $date = $trips->week_days;
                    $action = '<a href="'.url('admin/per_week_report/company/'.$trips->company_id).'/'.$date['week_start'].'/'.$date['week_end'].'" class="btn btn-xs btn-primary"><i class="fa fa-eye"></i></a>';
                    $payment_action = '<form action="'.url('admin/make_payout/company').'" method="post" name="payout_form" style="display:inline-block">
                        <input type="hidden" name="type" value="company_weekly">
                        <input type="hidden" name="_token" value="'.csrf_token().'">
                        <input type="hidden" name="company_id" value="'.$trips->company_id.'">
                        <input type="hidden" name="start_date" value="'.$date['week_start'].'">
                        <input type="hidden" name="end_date" value="'.$date['week_end'].'">
                        <input type="hidden" name="redirect_url" value="admin/weekly_payout/company/'.$trips->company_id.'">
                        <button type="submit" class="btn btn-primary make-pay-btn" name="submit" value="submit"> Paid </button>
                        
                        </form>';
                }
                
                return '<div>'.$action.''.$payment_action.'</div>';
            })
            ->make(true);
    }

    /**
     * Get the query object to be processed by datatables.
     *
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        $company_id = request()->company_id;

        $trips = Trips::CompanyPayoutTripsOnly()->select('trips.*','users.company_id as company_id','companies.name as company_name')
        ->with(['currency','driver']);

        if($this->filter_type == 'Weekly') {
            $trips = $trips
            ->whereHas('driver',function($q) use ($company_id){
                $q->where('company_id',$company_id);
            })
            ->groupBy(DB::raw('WEEK(trips.created_at)'));
        }
        else if($this->filter_type == 'OverAll') {
            $trips = $trips->groupBy('users.company_id');
        }

        $trips = $trips->get();
        return $this->applyScopes($trips);
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \yajra\Datatables\Html\Builder
     */
    public function html()
    {
        if($this->filter_type == 'Weekly') {
            $payout_columns = array(
                ['data' => 'company_id', 'name' => 'trips.company_id', 'title' => 'Company Id'],
                ['data' => 'company_name', 'name' => 'company_name', 'title' => 'Company Name'],
                ['data' => 'week_day', 'name' => 'week_day', 'title' => 'Week Day'],
                ['data' => 'company_payout', 'name' => 'company_payout', 'title' => 'Payout Amount'],
                
            );                
        }
        else {
            $payout_columns = array(
                ['data' => 'company_id', 'name' => 'trips.company_id', 'title' => 'Company Id'],
                ['data' => 'company_name', 'name' => 'company_name', 'title' => 'Company Name'],
                ['data' => 'company_payout', 'name' => 'company_payout', 'title' => 'Payout Amount'],
            );
        }
        
        return $this->builder()
        ->columns($payout_columns)
        ->addColumn(['data' => 'action', 'name' => 'action', 'title' => 'Action', 'orderable' => false, 'searchable' => false])
        ->parameters([
            'dom' => 'lBfrtip',
            'buttons' => ['csv', 'excel', 'print', 'reset'],
            'order' => [0, 'desc'],
        ]);
    }

    /**
     * Build excel file and prepare for export.
     *
     * @return \Maatwebsite\Excel\Writers\LaravelExcelWriter
     */
    protected function buildExcelFile()
    {

        $width = array(
            'A' => '1',
            'B' => '2',
            'C' => '2',
            'D' => '2',
            'E' => '2',
            'F' => '1',
            'G' => '2',
            'H' => '3',
        );
        return Helpers::buildExcelFile($this->getFilename(), $this->getDataForExport(), $width);
    }
}

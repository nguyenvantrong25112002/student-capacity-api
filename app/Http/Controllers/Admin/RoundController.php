<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Round\RequestRound;
use App\Models\Contest;
use App\Models\Donor;
use App\Models\DonorRound;
use App\Models\Enterprise;
use App\Models\Evaluation;
use App\Models\HistoryPoint;
use App\Models\Judge;
use App\Models\Result;
use App\Models\Round;
use App\Models\RoundTeam;
use App\Models\TakeExam;
use App\Models\Team;
use App\Models\TypeExam;
use App\Services\Modules\MContest\MContestInterface;
use App\Services\Modules\MRound\MRoundInterface;
use App\Services\Modules\MRoundTeam\MRoundTeamInterface;
use App\Services\Modules\MTeam\MTeamInterface;
use App\Services\Traits\TResponse;
use App\Services\Traits\TUploadImage;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Image;

class RoundController extends Controller
{
    use TResponse, TUploadImage;

    public function __construct(
        private Judge $judge,
        private Round $round,
        private RoundTeam $roundTeam,
        private MRoundTeamInterface $roundTeamRepo,
        private MRoundInterface $roundRepo,
        private Contest $contest,
        private TypeExam $type_exam,
        private Team $team,
        private MTeamInterface $teamRepo,
        private DB $db,
        private MContestInterface $mContestInterfacet
    ) {
    }

    //  View round
    public function index()
    {
        if (!($rounds = $this->roundRepo->index())) return abort(404);
        return view('pages.round.index', [
            'rounds' => $rounds,
            'contests' => $this->contest::withCount(['teams', 'rounds'])->get(),
            'type_exams' => $this->type_exam::all(),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/public/rounds",
     *     description="Description api round",
     *     tags={"Round"},
     *     @OA\Parameter(
     *         name="q",
     *         in="query",
     *         description="T??m ki???m ",
     *         required=false,
     *     ),
     *     @OA\Parameter(
     *         name="sort",
     *         in="query",
     *         description="L???c theo chi???u asc ho???c desc ",
     *         required=false,
     *     ),
     *     @OA\Parameter(
     *         name="sort_by",
     *         in="query",
     *         description="C??c c???t c???n l???c  ",
     *         required=false,
     *     ),
     *     @OA\Parameter(
     *         name="contest_id",
     *         in="query",
     *         description="L???c theo cu???c thi   ",
     *         required=false,
     *     ),
     *     @OA\Parameter(
     *         name="type_exam_id",
     *         in="query",
     *         description="L???c theo th??? lo???i thi   ",
     *         required=false,
     *     ),
     *     @OA\Response(response="200", description="{ status: true , data : data }"),
     *     @OA\Response(response="404", description="{ status: false , message : 'Not found' }")
     * )
     */
    //  Response round
    public function apiIndex()
    {
        if (!($data = $this->roundRepo->apiIndex())) return $this->responseApi(false);
        return $this->responseApi(true, $data);
    }

    public function create(TypeExam $typeExam)
    {
        $contests = $this->contest::all();
        $typeexams = $typeExam::all();
        // $nameTypeContest = request('type') == 1 ? ' b??i l??m  ' : ' v??ng thi';
        $nameTypeContest = ' v??ng thi';
        return view('pages.round.form-add', compact('contests', 'typeexams', 'nameTypeContest'));
    }

    public function store(RequestRound $request)
    {
        $contest = $this->contest::find($request->contest_id);
        if (!$contest) return abort(404);
        if (Carbon::parse($request->start_time)->toDateTimeString() < Carbon::parse($contest->date_start)->toDateTimeString()) {
            return redirect()->back()->withErrors(['start_time' => 'Th???i gian b???t ?????u kh??ng ???????c b?? h??n th???i gian b???t ?????u c???a cu???c thi !'])->withInput();
        };
        if (Carbon::parse($request->end_time)->toDateTimeString() > Carbon::parse($contest->register_deadline)->toDateTimeString()) {
            return redirect()->back()->withErrors(['end_time' => 'Th???i gian k???t th??c kh??ng ???????c l???n h??n th???i gian k???t th??c c???a cu???c thi v?? test n??ng l???c  !'])->withInput();
        };
        $this->db::beginTransaction();
        try {
            $this->roundRepo->store($request);
            $this->db::commit();
            if ($contest->type == 1)  return redirect()->route('admin.contest.show.capatity', ['id' => $contest->id]);
            return redirect()->route('admin.contest.detail.round', ['id' => $contest->id]);
        } catch (Exception $ex) {
            if ($request->hasFile('image')) {
                $fileImage = $request->file('image');
                if (Storage::disk('s3')->has($filename)) {
                    Storage::disk('s3')->delete($filename);
                }
            }
            $this->db::rollBack();
            return Redirect::back()->with(['error' => 'Th??m m???i th???t b???i !']);
        }
    }

    public function edit($id)
    {
        try {
            $round = $this->round::where('id', $id)->with('contest')->first()->toArray();
            if ($round['contest']['type'] != request('type')) abort(404);
            return view('pages.round.edit', [
                'round' => $round,
                'contests' => $this->contest::all(),
                'type_exams' => $this->type_exam::all(),
                'nameContestType' =>   ' v??ng thi'
                // 'nameContestType' => request('type') == 1 ? ' b??i l??m ' : ' v??ng thi'
            ]);
        } catch (\Throwable $th) {
            return abort(404);
        }
    }

    private function updateRound($request, $id)
    {
        try {
            // dd(request()->all());
            if (!($round = $this->round::find($id))) return false;
            $contest = $this->contest::find($request->contest_id);
            if (!$contest) return false;
            if (Carbon::parse($request->start_time)->toDateTimeString() < Carbon::parse($contest->date_start)->toDateTimeString()) {
                return [
                    'status' => false,
                    'errors' => ['start_time' => 'Th???i gian b???t ?????u kh??ng ???????c b?? h??n th???i gian b???t ?????u c???a cu???c thi !'],
                ];
            };
            if (Carbon::parse($request->end_time)->toDateTimeString() > Carbon::parse($contest->register_deadline)->toDateTimeString()) {
                return [
                    'status' => false,
                    'errors' => ['end_time' => 'Th???i gian k???t th??c kh??ng ???????c l???n h??n th???i gian k???t th??c c???a cu???c thi v?? test n??ng l???c  !'],
                ];
            };
            $data = null;
            if ($request->has('image')) {
                $nameFile = $this->uploadFile(request()->image, $round->image);
                $data = array_merge($request->except('image'), [
                    'image' => $nameFile,
                ]);
            } else {
                $data = request()->all();
            }
            if ($round->start_time < now()) unset($data['start_time']);
            $round->update($data);
            return [
                'round' => $round,
                'contest' => $contest
            ];
        } catch (\Throwable $th) {
            return false;
        }
    }

    // View round
    public function update(RequestRound $request, $id)
    {
        if ($data = $this->updateRound($request, $id)) {
            if (isset($data['status']) && $data['status'] == false)
                return redirect()->back()->withErrors($data['errors'])->withInput();
            if ($data['contest']->type == 1)  return redirect()->route('admin.contest.show.capatity', ['id' => $data['contest']->id]);
            return redirect()->route('admin.contest.detail.round', ['id' => $data['contest']->id]);
        }
        return abort(404);
    }

    private function destroyRound($id)
    {
        try {
            if (!(auth()->user()->hasRole(config('util.ROLE_DELETE'))))   return false;

            $this->db::transaction(function () use ($id) {
                if (!($data = $this->round::find($id)))  return false;
                if (Storage::disk('s3')->has($data->image)) Storage::disk('s3')->delete($data->image);
                $data->delete();
            });
            return true;
        } catch (\Throwable $th) {
            return false;
        }
    }

    // View round
    public function destroy($id)
    {
        if (!(auth()->user()->hasRole(config('util.ROLE_DELETE'))))  return redirect()->back()->with('error', 'Kh??ng th??? x??a ');
        if ($this->destroyRound($id))  return redirect()->back();
        return redirect('error');
    }

    /**
     * @OA\Get(
     *     path="/api/public/rounds/{id}",
     *     description="Description api round by id",
     *     tags={"Round"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID v??ng thi",
     *         required=true,
     *     ),
     *     @OA\Response(response="200", description="{ status: true , data : data }"),
     *     @OA\Response(response="404", description="{ status: false , message : 'Not found' }")
     * )
     */
    public function show($id)
    {
        $round = $this->round::whereId($id);
        if (is_null($round)) {
            return $this->responseApi(false, 'Kh??ng t???n t???i trong h??? th???ng !');
        } {
            $round->with('contest');
            $round->with('type_exam');
            $round->with(['judges']);
            $round->with(['teams' => function ($q) {
                return $q->with('members');
            }]);

            return $this->responseApi(
                true,
                $round
                    ->get()
                    ->map(function ($col, $key) {
                        if ($key > 0) return;
                        $col = $col->toArray();
                        $user = [];
                        foreach ($col['judges'] as $judge) {
                            array_push($user, $judge['user']);
                        }
                        $arrResult = array_merge($col, [
                            'judges' => $user
                        ]);
                        return $arrResult;
                    })[0]
            );
        }
    }

    public function contestDetailRound($id)
    {
        if (!($rounds = $this->roundRepo->getList())) {
            return view('not_found');
        }

        $contest = $this->contest->find($id);
        return view('pages.contest.detail.contest-round', [
            'rounds' => $rounds->where('contest_id', $id)
                ->when(
                    auth()->check() && auth()->user()->hasRole('judge'),
                    function ($q) use ($id) {
                        $judge = $this->judge::where('contest_id', $id)
                            ->where('user_id', auth()->user()->id)
                            ->with('judge_round')
                            ->first('id');
                        $arrId = [];
                        foreach ($judge->judge_round as $judge_round) {
                            array_push($arrId, $judge_round->id);
                        }
                        return $q->whereIn('id', $arrId);
                    }
                )
                ->withCount(['results', 'exams', 'posts', 'sliders'])
                ->paginate(request('limit') ?? 5),
            'contests' => $this->contest::withCount(['teams', 'rounds'])->get(),
            'type_exams' => $this->type_exam::all(),
            'contest' => $contest
        ]);
    }

    public function adminShow($id)
    {
        if (!($round = $this->round::with(['contest', 'type_exam', 'judges', 'teams', 'Donor'])->where('id', $id)->first())) {
            return abort(404);
        }

        $roundTeam = RoundTeam::where('round_id', $id)
            ->where('status', config('util.ROUND_TEAM_STATUS_NOT_ANNOUNCED'))
            ->get();
        return view('pages.round.detail.detail', ['round' => $round, 'roundTeam' => $roundTeam]);
    }

    /**
     * Update tr???ng th??i ?????i thi trong v??ng thi ti???p theo
     */
    public function roundDetailUpdateRoundTeam($id)
    {
        try {
            $roundTeam = RoundTeam::where('round_id', $id)->where('status', config('util.ROUND_TEAM_STATUS_NOT_ANNOUNCED'))->get();
            if (count($roundTeam) > 0) {
                foreach ($roundTeam as $item) {
                    $updateRoundTeam = RoundTeam::find($item->id);
                    $updateRoundTeam->status = config('util.ROUND_TEAM_STATUS_ANNOUNCED');
                    $updateRoundTeam->save();
                }
            }

            return redirect()->back();
        } catch (\Throwable $th) {
            return redirect()->back();
        }
    }

    // chi ti???t doanh nghi???p
    public function roundDetailEnterprise($id)
    {

        if (!($round = $this->round->find($id)->load('Donor')->Donor()->paginate(6))) {
            return abort(404);
        }
        // dd($round);
        $enterprise = Enterprise::all();
        return view('pages.round.detail.enterprise', ['round' => $round, 'roundDeltai' => $this->round->find($id), 'enterprise' => $enterprise]);
    }

    public function softDelete()
    {
        $listRoundSofts = $this->roundRepo->getList()->paginate(request('limit') ?? 5);
        return view('pages.round.round-soft-delete', [
            'listRoundSofts' => $listRoundSofts,
        ]);
    }

    public function backUpRound($id)
    {
        try {
            $this->round::withTrashed()->where('id', $id)->restore();
            return redirect()->back();
        } catch (\Throwable $th) {
            return abort(404);
        }
    }

    public function deleteRound($id)
    {
        try {
            $this->round::withTrashed()->where('id', $id)->forceDelete();
            return redirect()->back();
        } catch (\Throwable $th) {
            return abort(404);
        }
    }

    public function roundDetailTeam($id)
    {
        $round = $this->roundRepo->find($id);
        $roundTeams = $this->roundTeamRepo->getRoundTeamByRoundId($id, [
            'team',
            'takeExam' => function ($q) {
                return $q->with('exam');
            }
        ]);
        $teamContest = $this->teamRepo->getTeamByContestId($round->contest_id);
        return view('pages.round.detail.round-team', compact('round', 'roundTeams', 'teamContest'));
    }

    public function attachEnterprise(Request $request, Donor $donor, DonorRound $donorRound, $id)
    {
        try {
            // dd(Round::find($id)->load('Enterprise')->Enterprise->id);
            foreach ($request->enterprise_id as $item) {
                $data = $donor::where('contest_id', $this->round::find($id)->load('enterprise_contest:id')->enterprise_contest->id)
                    ->where('enterprise_id', $item)
                    ->first();
                if ($data != null) {
                    $donorRound::create([
                        'round_id' => $id,
                        'donor_id' => $data->id,
                    ]);
                    return redirect()->back();
                }
                $data = Donor::create([
                    'contest_id' => Round::find($id)->load('enterprise_contest:id')->enterprise_contest->id,
                    'enterprise_id' => $item,
                ]);
                $donorRound::create([
                    'round_id' => $id,
                    'donor_id' => $data->id,
                ]);
            }
            return redirect()->back();
        } catch (\Throwable $th) {
            return redirect()->back();
        }
    }

    public function detachEnterprise($id, $donor_id)
    {
        try {
            $data = DonorRound::where('round_id', $id)->where('donor_id', $donor_id)->first();
            if ($data) $data->delete();
            return redirect()->back();
        } catch (\Throwable $th) {
            return redirect()->back();
        }
    }

    public function attachTeam(Request $request, $id)
    {
        try {
            $this->round::find($id)->teams()->syncWithoutDetaching($request->team_id);
            return redirect()->back();
        } catch (\Throwable $th) {
            return redirect()->back();
        }
    }

    public function detachTeam($id, $team_id)
    {
        try {
            $this->round::find($id)->teams()->detach([$team_id]);
            return redirect()->back();
        } catch (\Throwable $th) {
            return redirect()->back();
        }
    }

    /**
     *  Chi ti???t ?????i thi ??? v??ng thi
     */
    public function roundDetailTeamDetail($id, $teamId)
    {

        try {
            $round = $this->round::find($id);
            $team = Team::where('id', $teamId)->first();
            return view(
                'pages.round.detail.team.index',
                [
                    'round' => $round,
                    'team' => $team,
                ]
            );
        } catch (\Throwable $th) {
            return redirect()->back();
        }
    }

    /**
     *   chi ti???t b??i thi c???a ?????i thi theo v??ng thi
     */

    public function roundDetailTeamTakeExam($id, $teamId)
    {
        try {
            $round = $this->round::find($id);
            $team = Team::where('id', $teamId)->first();
            $takeExam = RoundTeam::where('round_id', $id)->where('team_id', $teamId)->with('takeExam')->first();
            return view(
                'pages.round.detail.team.team_take_exam',
                [
                    'takeExam' => $takeExam->takeExam,
                    'round' => $round,
                    'team' => $team,
                ]
            );
        } catch (\Throwable $th) {
            return abort(404);
        }
    }

    public function roundDetailTeamMakeExam($id, $teamId)
    {

        try {
            $round = $this->round::find($id);

            $team = Team::where('id', $teamId)->first();
            $takeExam = RoundTeam::where('round_id', $id)
                ->where('team_id', $teamId)
                ->with('takeExam', function ($q) use ($round) {
                    return $q->with(['exam', 'evaluations' => function ($q) use ($round) {
                        $judge = $this->judge::where('contest_id', $round->contest_id)
                            ->where('user_id', auth()->user()->id)->with('judge_rounds', function ($q) use ($round) {
                                return $q->where('round_id', $round->id);
                            })
                            ->first('id');
                        return $q->where('judge_round_id',   isset($judge->judge_rounds[0]) ?  $judge->judge_rounds[0]->id : []);
                    }]);
                })
                ->first();
            return view(
                'pages.round.detail.team-make-exam',
                [
                    'takeExam' => $takeExam->takeExam,
                    'round' => $round,
                    'team' => $team,
                ]
            );
        } catch (\Throwable $th) {
            return abort(404);
        }
    }

    public function roundDetailFinalTeamMakeExam(Request $request, $id, $teamId)
    {
        $round = $this->round::find($id);
        $team = Team::where('id', $teamId)->first();
        $roundTeam = RoundTeam::where('round_id', $id)
            ->where('team_id', $teamId)
            ->with('takeExam', function ($q) use ($round) {
                return $q->with(['exam', 'evaluations']);
            })->first();

        $request->validate([
            'ponit' => 'required|numeric|min:0|max:' . $roundTeam->takeExam->exam->max_ponit,
            'comment' => 'required',
            'status' => 'required',
        ], [
            'ponit.required' => 'Tr?????ng ??i???m kh??ng b??? tr???ng !',
            'ponit.numeric' => 'Tr?????ng ??i???m ????ng ?????nh d???ng !',
            'ponit.min' => 'Tr?????ng ??i???m ph???i thu???c s??? d????ng  !',
            'ponit.max' => 'Tr?????ng ??i???m kh??ng l???n h??n thang ??i???m ' . $roundTeam->takeExam->exam->max_ponit . '!',
            'comment.required' => 'Tr?????ng nh???n x??t kh??ng b??? tr???ng !',
        ]);
        $judge = $this->judge::where('contest_id', $round->contest_id)->where('user_id', auth()->user()->id)->with('judge_rounds', function ($q) use ($round) {
            return $q->where('round_id', $round->id);
        })->first('id');
        $dataCreate = array_merge($request->only([
            'ponit',
            'comment',
            'status',
        ]), [
            'exams_team_id' => $roundTeam->takeExam->id,
            'judge_round_id' => $judge->judge_rounds[0]->id,
        ]);
        try {
            $evaluetion = Evaluation::create($dataCreate);
            $data = [
                'point' => $request->ponit,
                'reason' => $request->has('reason') ? $request->reason : null,
                'user_id' => auth()->user()->id,
            ];
            $findEvaluation = Evaluation::find($evaluetion->id);
            $findEvaluation->history_point()->create($data);
            return redirect()->back();
            return redirect()->route('admin.round.detail.team', ['id' => $round->id]);
        } catch (\Throwable $th) {
            return abort(404);
        }
    }

    public function roundDetailUpdateTeamMakeExam(Request $request, $id, $teamId)
    {
        $round = $this->round::find($id);
        $roundTeam = RoundTeam::where('round_id', $id)
            ->where('team_id', $teamId)
            ->with('takeExam', function ($q) use ($round) {
                return $q->with(['exam', 'evaluations']);
            })->first();

        $request->validate([
            'ponit' => 'required|numeric|min:0|max:' . $roundTeam->takeExam->exam->max_ponit,
            'comment' => 'required',
            'status' => 'required',
        ], [
            'ponit.required' => 'Tr?????ng ??i???m kh??ng b??? tr???ng !',
            'ponit.numeric' => 'Tr?????ng ??i???m ????ng ?????nh d???ng !',
            'ponit.min' => 'Tr?????ng ??i???m ph???i thu???c s??? d????ng  !',
            'ponit.max' => 'Tr?????ng ??i???m kh??ng l???n h??n thang ??i???m ' . $roundTeam->takeExam->exam->max_ponit . '!',
            'comment.required' => 'Tr?????ng nh???n x??t kh??ng b??? tr???ng !',
        ]);
        $judge = $this->judge::where('contest_id', $round->contest_id)->where('user_id', auth()->user()->id)->with('judge_rounds', function ($q) use ($round) {
            return $q->where('round_id', $round->id);
        })->first('id');

        $ev = Evaluation::where('exams_team_id', $roundTeam->takeExam->id)
            ->where('judge_round_id', $judge->judge_rounds[0]->id)->first();

        $dataCreate = array_merge($request->only([
            'ponit',
            'comment',
            'status',
        ]), [
            'exams_team_id' => $roundTeam->takeExam->id,
            'judge_round_id' => $judge->judge_rounds[0]->id,
        ]);
        $data = [
            'point' => $request->ponit,
            'reason' => $request->has('reason') ? $request->reason : null,
            'user_id' => auth()->user()->id,
        ];
        try {

            $ev->update($dataCreate);
            $findEvaluation = Evaluation::find($ev->id);
            $findEvaluation->history_point()->create($data);
            return redirect()->back();
            return redirect()->route('admin.round.detail.team', ['id' => $round->id]);
        } catch (\Throwable $th) {
            return abort(404);
        }
    }

    /**
     * UPdate ??i???m b??i thi cho ?????i thi
     */
    public function roundDetailTeamTakeExamUpdate(Request $request, $id, $teamId, $takeExamId)
    {
        DB::beginTransaction();
        try {
            $dataCreate = [
                'point' => $request->final_point,
                'reason' => $request->has('reason') ? $request->reason : null,
                'user_id' => auth()->user()->id,
            ];

            $takeExam = TakeExam::find($takeExamId);
            $result = Result::where('round_id', $id)->where('team_id', $teamId)->first();
            if ($result) {
                $result->point = $request->final_point;
                $result->save();
            } else {
                Result::create([
                    "point" =>  $request->final_point,
                    "round_id" =>  $id,
                    "team_id" =>  $teamId,
                ]);
            }
            if ($takeExam) {
                $takeExam->history_point()->create($dataCreate);
                $takeExam->final_point = $request->final_point;
                $takeExam->mark_comment = $request->has('mark_comment') ? $request->mark_comment : null;
                $takeExam->save();
            }
            $check = RoundTeam::where('round_id', $id)->where('team_id', $teamId)->first();

            if ($check == null && $request->final_point >= $request->ponit) {
                RoundTeam::create([
                    'round_id' => $id,
                    'team_id' => $teamId,
                    'status' => config('util.ROUND_TEAM_STATUS_NOT_ANNOUNCED'), // Ch??a c??ng b???
                ]);
            } elseif ($check && $request->final_point < $request->ponit) {
                $check->delete();
            }
            DB::commit();

            echo "<script>art('Th??nh c??ng ')<script/>";
            return redirect()->back();
        } catch (\Throwable $th) {
            DB::rollBack();
            return redirect()->back()->withErrors(['message', "???? x???y ra l???i !"]);
        }
    }

    /**
     * chi ti???t ????? thi theo t???ng ?????i thi trong v??ng thi
     */
    public function roundDetailTeamExam($id, $teamId)
    {
        try {
            $round = $this->round::find($id);
            $team = Team::where('id', $teamId)->first();
            $takeExam = RoundTeam::where('round_id', $id)->where('team_id', $teamId)->first();
            // dd($takeExam->takeExam->exam);
            return view(
                'pages.round.detail.team.team_exam',
                [
                    'Exam' => $takeExam->takeExam,
                    'round' => $round,
                    'team' => $team,
                ]
            );
        } catch (\Throwable $th) {
            return redirect()->back();
        }
    }

    public function roundDetailTeamJudge($id, $teamId)
    {

        try {
            $data = [];
            $round = $this->round::find($id);
            $team = Team::where('id', $teamId)->first();
            $takeExam = RoundTeam::where('round_id', $id)->where('team_id', $teamId)->first()->takeExam;


            if ($takeExam != null && count($takeExam->evaluation) > 0) {

                foreach ($takeExam->evaluation as $key => $item) {
                    $data[$key] = $item->id;
                }
                $historyPoint2 = HistoryPoint::whereIn('historiable_id', $data)
                    ->with(['user'])
                    ->orderByDesc('id')->get();
                $historyPoint = HistoryPoint::where('historiable_id', $takeExam->id)
                    ->with(['user'])
                    ->orderByDesc('id')
                    ->get();
            }

            return view(
                'pages.round.detail.team.team_judges_result',
                [
                    'judgesResult' => $takeExam,
                    'round' => $round,
                    'team' => $team,
                    'historyPoint2' => isset($historyPoint2) ? $historyPoint2 : null,
                    'historyPoint' => isset($historyPoint) ? $historyPoint : null,
                ]
            );
        } catch (\Throwable $th) {
            abort(404);
        }
    }

    public function sendMail($id)
    {
        $round = $this->round::findOrFail($id)->load([
            'judges',
            'teams' => function ($q) {
                return $q->with(['members']);
            },
        ]);
        $judges = [];
        if (count($round->judges) > 0) {
            foreach ($round->judges as $judge) {
                array_push($judges, $judge->user);
            }
        }
        $users = [];
        if (count($round->teams) > 0) {
            foreach ($round->teams as $team) {
                foreach ($team->members as $user) {
                    array_push($users, $user);
                }
            }
        }
        return view('pages.round.add-mail', ['round' => $round, 'judges' => $judges, 'users' => array_unique($users)]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/round/{id_round}/team-me",
     *     description="Description api user team round",
     *     tags={"Round","Team","Contest","Api V1"},
     *     summary="Authorization",
     *     security={{"bearer_token":{}}},
     *     @OA\Parameter(
     *         name="id_round",
     *         in="path",
     *         description="Id v??ng thi  test n??ng l???c  ",
     *         required=true,
     *     ),
     *     @OA\Response(response="200", description="{ status: true , data : data }"),
     *     @OA\Response(response="404", description="{ status: false , message : 'Not found' }")
     * )
     */
    public function userTeamRound($roundId, Round $round)
    {
        $team_id = 0;
        $user_id = auth('sanctum')->user()->id;
        $round = $round::find($roundId)->load('teams');
        try {
            if ($round->teams) {
                foreach ($round->teams as $team) {
                    foreach ($team->users as $user) {
                        if ($user->id == $user_id) {
                            $team_id = $team->id;
                        }
                    }
                }
            }
            if ($team_id == 0)  return $this->responseApi(true, []);
            $team = $this->team::find($team_id)->load('members');
            return $this->responseApi(true, $team);
        } catch (\Throwable $th) {
            return $this->responseApi(false);
        }
    }

    public function nextRoundCapacity(Request $request)
    {
        try {
            $user_id = auth('sanctum')->user()->id;
            $contest = $this->mContestInterfacet->find($request->contest_id);
            if (is_null($contest)) return $this->responseApi(false, 'Kh??ng t??m th???y cu???c thi !!');
            $rounds = $this->roundRepo->where(['contest_id' => $request->contest_id])
                ->select('id')->with(['result_capacity' => function ($q)  use ($user_id) {
                    $q->where('result_capacity.user_id', $user_id);
                }])->get();
            $userJoinedRound = [];
            $userHasNotJoinedRound = [];
            foreach ($rounds as $round) {
                if (count($round->result_capacity) > 0) {
                    if ($round->result_capacity[0]['status'] == config('util.STATUS_RESULT_CAPACITY_DOING')) {
                        array_push($userHasNotJoinedRound, $round->id);
                    } else {
                        array_push($userJoinedRound, $round->id);
                    }
                } else {
                    array_push($userHasNotJoinedRound, $round->id);
                }
            }
            if (count($rounds) === count($userJoinedRound)) {
                return $this->responseApi(false, 'B???n ???? ho??n th??nh t???t c??? c??c v??ng thi !!');
            }
            if (count($rounds) >= count($userHasNotJoinedRound)) {
                $round = $this->roundRepo->whereIn('id', $userHasNotJoinedRound)
                    ->orderBy('start_time', 'asc')->first();
                return $this->responseApi(true, $round);
            }
        } catch (\Throwable $th) {
            dd($th);
        }
    }
}

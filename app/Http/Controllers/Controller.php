<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use App\Services\EmojiService;
use App\Services\RandomUserService;
use App\Services\ReactionService;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use Validator;
use App\User;
use App\Chat;
use App\Picture;
use Illuminate\Support\Facades\Http;
use App\UserInfo;
use Illuminate\Support\Facades\URL;
use App\UserSettings;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Password;



class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
    /**
     * API Login, on success return JWT Auth token
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private EmojiService $emojiService;
    private RandomUserService $randomUserService;

    public function __construct(ReactionService $reactionService, EmojiService $emojiService, RandomUserService $randomUserService)
    {
        //$this->middleware('auth');
        $this->emojiService = $emojiService;
        $this->reactionService = $reactionService;
        $this->randomUserService = $randomUserService;
    }
    public function login(Request $request)
    {
        $credentials = json_decode(request()->getContent(), true);
        $rules = [
            'email' => 'required|email'
            //'password' => 'required',
        ];
        $validator = Validator::make($credentials, $rules);
        $requiredKeys = ['email'];
        $newArray = array();
        foreach ($credentials as $key => $value) {
            if (in_array($key, $requiredKeys))
                $newArray[$key] = $credentials[$key];
        }
        $credentials = $newArray;
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
        }

        try {
            //Check account available or not
            $findUser = User::where('email', $credentials['email'])->first();
            if ($findUser == null) {
                return response()->json(['success' => false, 'errors' => ['We cant find an account with this credentials.']], 401);
            }
            // attempt to verify the credentials and create a token for the user
            if (!$token = JWTAuth::fromUser($findUser)) {
                User::where('email', $credentials['email']);
                return response()->json(['success' => false, 'errors' => ['Invalid credentials. Please try again']], 401);
            }
        } catch (JWTException $e) {
            // something went wrong whilst attempting to encode the token
            return response()->json(['success' => false, 'errors' => [$e->getMessage()]], 204);
        }
        // all good so return the token

        if ($findUser['is_verified'] != 1) {
            return response()->json(['success' => false, 'errors' => ['Your account is still not activated yet. Kindly check your email.']], 401);
        }
        if (isset($request->device_token) && !empty($request->device_token)) {
            $findUser->device_token = $request->device_token;
            $findUser->save();
        }
        $userInfo = $findUser->info;
        //dd($userInfo);
        $userObj = new \stdClass();
        $userObj->userid = $userInfo['user_id'];
        $userObj->firstname = $userInfo['name'];
        $userObj->lastname = $userInfo['surname'];
        $userObj->email = $findUser['email'];
        $payloadable = [
            'email' => $findUser['email']
        ];

        // $token = JWTAuth::claims($payloadable)->attempt($credentials);
        // if($token){
        //     $updateToken = User::where('email',$credentials['email'])->update(['remember_token'=>$token]);
        // }
        return response()->json(['success' => true, 'data' => ['user' => $userObj], 'expires_in' => auth()->factory()->getTTL() * 600]);
    }

    public function removeUserProfile(Request $request)
    {
        $reqData = request()->all();
        $userId = $reqData['user_id'];
        if (isset($userId)) {
            User::find($userId)->delete();
            DB::table('user_infos')->where('user_id', $userId)->delete();
            DB::table('user_settings')->where('user_id', $userId)->delete();
            return response()->json(['success' => true, 'message' => "User account removed successfully."]);
        } else {
            return response()->json(['success' => false, 'message' => "User account not found."]);
        }
    }

    public function logout(Request $request)
    {
        $this->validate($request, ['token' => 'required']);
        try {
            JWTAuth::invalidate($request->get('token'));
            return response()->json(['success' => true, 'message' => "You have successfully logged out."]);
        } catch (JWTException $e) {
            // something went wrong whilst attempting to encode the token
            return response()->json(['success' => false, 'errors' => ['Failed to logout, please try again.']], 204);
        }
    }

    public function register(Request $request)
    {
        $reqData = request()->all();
        //dd($reqData);
        $reqData = json_decode($reqData['data']);
        $rules = [
            'firstname' => ['required', 'string', 'min:3', 'max:255'],
            'lastname' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'max:8', 'unique:user_infos'],
            'age' => ['required', 'int', 'min:18', 'max:100'],
            'gender' => ['required'],
            'description' => ['required', 'min:10', 'max:255'],
            'relationship' => ['required'],
            'img' => ['required'],
            'country' => ['required'],
            //'languages' => ['required', 'min:2', 'max:255'],
            //'search_age_range' => ['required'],
            'search_male' => ['required_unless:search_female,1'],
            'search_female' => ['required_unless:search_male,1'],
            'religion' => ['required'],
            'marital_status' => ['required'],
            'children' => ['required'],
            'want_children' => ['required'],
            'drinks' => ['required'],
            'smokes' => ['required'],
            'profession' => ['required'],
            //'interests' => ['required'],
            'DOB' => ['required'],
            'location' => ['required'],
            'here_for' => ['required'],
            'height' => ['required'],
            'body_type' => ['required']
        ];
        //dd($reqData);
        // $validator = Validator::make($reqData, $rules);
        // if ($validator->fails()) {
        //     return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
        // }

        $checkExist = User::where('email', $reqData->email)->get();
        if (count($checkExist) > 0) {
            return response()->json(['success' => false, 'errors' => ['Email address already exist.']], 200);
        }

        try {
            $images = $request->file('img');
            $profilePicture = $request->file('profile_picture');
            // Read image path, convert to base64 encoding
            // $imagedata = file_get_contents($images[0]);
            //  // alternatively specify an URL, if PHP settings allow
            // $base64 = base64_encode($imagedata);
            // $base64decode = base64_decode($base64);
            //dd($images);
            $imgURL = [];
            foreach ($images as $image) {
                $rand = substr(uniqid('', true), -5);
                $name = $rand . time() . '.' . $image->getClientOriginalExtension();
                $destinationPath = public_path('/images');
                $image->move($destinationPath, $name);
                $imgURL[] = URL::to('/') . '/images/' . $name;
            }
            if (isset($profilePicture)) {
                $rand = substr(uniqid('', true), -5);
                $name = $rand . time() . '.' . $profilePicture->getClientOriginalExtension();
                $destinationPath = public_path('/images');
                $profilePicture->move($destinationPath, $name);
                $profilePic = URL::to('/') . '/images/' . $name;
            }
            //dd($imgURL);
            DB::beginTransaction();

            $user = User::create([
                'email' => $reqData->email,
                'device_token' => (isset($request->device_token) && !empty($request->device_token)) ? $request->device_token : '',
                //'password' => Hash::make($reqData->password)
            ]);
            //dd($imgURL);
            UserInfo::create([
                'user_id' => $user->id,
                'name' => $reqData->name,
                'phone' => $reqData->phone,
                'age' => $reqData->age,
                'gender' => $reqData->gender,
                'profile_picture' => isset($profilePic) ? $profilePic : "",
                'description' => $reqData->description,
                'relationship' => $reqData->relationship,
                'country' => $reqData->country,
                //'languages' => $reqData->languages,
                'religion' => $reqData->religion,
                'ethnicity' => $reqData->ethnicity,
                'marital_status' => $reqData->marital_status,
                'children' => $reqData->children,
                'want_children' => $reqData->want_children,
                'drinks' => $reqData->drinks,
                'smokes' => $reqData->smokes,
                'profession' => $reqData->profession,
                //'interests'=> $reqData->interests,
                'DOB' => $reqData->DOB,
                'latitude' => $reqData->latitude,
                'longitude' => $reqData->longitude,
                'hobbies' => $reqData->hobbies,
                'location' => $reqData->location,
                'here_for' => $reqData->here_for,
                'height' => $reqData->height,
                'body_type' => $reqData->body_type
            ]);
            //dd($reqData);
            foreach ($imgURL as $img) {
                Picture::create([
                    'user_id' => $user->id,
                    'path' => $img //$picture->store('profilePictures', 'public')
                ]);
            }

            $reqData->search_age_range = "20;34";
            $searchAgeRange = explode(';', $reqData->search_age_range);

            ($reqData->search_male == 1) ? $searchMale = 1 : $searchMale = 0;
            ($reqData->search_female == 1) ? $searchFemale = 1 : $searchFemale = 0;

            UserSettings::create([
                'user_id' => $user->id,
                'search_age_from' => $searchAgeRange[0],
                'search_age_to' => $searchAgeRange[1],
                'search_male' => $searchMale,
                'search_female' => $searchFemale
            ]);
            DB::commit();

            //$userInfo = $findUser->info;
            //dd($userInfo);
            $userObj = new \stdClass();
            $userObj->userid = $user->id;
            $userObj->name = $reqData->name;
            $userObj->email = $reqData->email;
            $userObj->dob = $reqData->DOB;
            $userObj->phone = $reqData->phone;
            $userObj->lookingfor = isset($searchMale) ? "Male" : "Female";
            $userObj->herefor = $reqData->here_for;
            $userObj->hobbies = $reqData->hobbies;
            $userObj->marital_status = $reqData->marital_status;
            $userObj->profession = $reqData->profession;
            $userObj->religion = $reqData->religion;
            $userObj->latitude = $reqData->latitude;
            $userObj->longitude = $reqData->longitude;

            $userObj->ethnicity = $reqData->ethnicity;
            $userObj->drinks = $reqData->drinks;
            $userObj->smokes = $reqData->smokes;
            $userObj->children = $reqData->children;
            $userObj->want_children = $reqData->want_children;
            $userObj->body_type = $reqData->body_type;
            $userObj->height = $reqData->height;

            return response()->json(['success' => true, 'message' => "You have registered successfully.", 'data' => ['user' => $userObj]]);
        } catch (\Exception $e) {
            //dd($e);
            DB::rollback();
            return response()->json(['success' => false, 'errors' => [$e->getMessage()]], 204);
        }
    }

    private function base64_to_jpeg($base64_string, $output_file)
    {
        // open the output file for writing
        $ifp = fopen($output_file, 'wb');

        // split the string on commas
        // $data[ 0 ] == "data:image/png;base64"
        // $data[ 1 ] == <actual base64 string>
        $data = explode(',', $base64_string);
        dd($data);
        // we could add validation here with ensuring count( $data ) > 1
        fwrite($ifp, base64_decode($data[1]));

        // clean up the file resource
        fclose($ifp);

        return $output_file;
    }


    public function rand6($min, $max)
    {
        $num = array();
        for ($i = 0; $i < 6; $i++) {
            $num[] = mt_rand($min, $max);
        }
        return $num;
    }


    private function getMatchPercentage($userId, $matchWithUserId)
    {
        if (isset($userId)) {
            // match percentage start
            $matchPercentage = 0;
            if (isset($userId['id'])) {
                $user = User::find($userId['id']);
            } else {
                $user = User::find($userId);
            }

            $userSettings = $user->settings;
            $userInfo = $user->info;

            $matchUsers = DB::table('users')
                ->leftJoin('user_infos', 'user_infos.user_id', '=', 'users.id')
                /*->where('user_infos.country',$userInfo['country'])
            ->where('user_infos.ethnicity',$userInfo['ethnicity'])
            ->whereIn('user_infos.here_for',explode(',',$userInfo['here_for']))
            ->whereIn('user_infos.hobbies',explode(',',$userInfo['hobbies']))
            ->where('user_infos.marital_status',$userInfo['marital_status'])*/
                ->where('user_infos.user_id', '!=', $userId)
                ->where('users.id', '=', $matchWithUserId);
            /*if($filter != null){
                $matchUsers->whereBetween('user_infos.age', [$ageExp[0], $ageExp[1]]);
                //HAVING distance <= '.$distance;
            }*/
            //var_dump($matchUsers->toSql());
            //dd($matchUsers->getBindings());

            //$totalRecords = $matchUsers->count();
            $matchUsers = $matchUsers->get();

            $userObj = [];
            foreach ($matchUsers as $user) {
                //dd($userInfo['relationship']);
                if ($user->gender != $userInfo['gender']) {
                    $matchPercentage += 10;
                }
                if ($user->relationship == $userInfo['relationship']) {
                    $matchPercentage += 10;
                }
                if ($user->country == $userInfo['country']) {
                    $matchPercentage += 10;
                }
                if ($user->languages == $userInfo['languages']) {
                    $matchPercentage += 10;
                }
                if ($user->religion == $userInfo['religion']) {
                    $matchPercentage += 10;
                }
                if ($user->ethnicity == $userInfo['ethnicity']) {
                    $matchPercentage += 5;
                }
                if ($user->marital_status != $userInfo['marital_status']) {
                    $matchPercentage += 5;
                }
                if ($user->children == $userInfo['children']) {
                    $matchPercentage += 5;
                }
                if ($user->want_children == $userInfo['want_children']) {
                    $matchPercentage += 5;
                }
                if ($user->drinks == $userInfo['drinks']) {
                    $matchPercentage += 5;
                }
                if ($user->smokes == $userInfo['smokes']) {
                    $matchPercentage += 5;
                }
                if ($user->profession == $userInfo['profession']) {
                    $matchPercentage += 5;
                }
                if ($user->interests == $userInfo['interests']) {
                    $matchPercentage += 5;
                }
                if ($user->hobbies == $userInfo['hobbies']) {
                    $matchPercentage += 5;
                }
                if ($user->here_for == $userInfo['here_for']) {
                    $matchPercentage += 5;
                }
            }

            return $matchPercentage . '%';
        }
    }

    public function getRandomUsers(Request $request)
    {
        $reqData = json_decode(request()->getContent(), true);
        $currentUser = $reqData['user_id'];
        $offset = $reqData['offset'];
        $page = $reqData['page'];

        $filter = isset($reqData['filter']) ? $reqData['filter'] : null;
        if ($filter != null) {
            $ageRange = isset($filter['age_range']) ? $filter['age_range'] : NULL;
            $distance = isset($filter['distance']) ? $filter['distance'] : 100;
            //$country = isset($filter['country']) ? strtolower($filter['country']) : NULL;
            $showMe = isset($filter['show_me']) ? strtolower($filter['show_me']) : NULL;
            if ($ageRange != NULL) {
                $ageExp = explode('-', $ageRange);
            }
        }
        $limit = 10;
        if ($page == 1) {
            $offset = 0;
        } else {
            $page = (int)$page - 1;
            $offset = $page . "1";
        }
        //dd($offset);

        $total = User::inRandomOrder()->get()->count();

        $users = DB::table('users')->select('user_infos.*')
            ->leftJoin('user_infos', 'users.id', '=', 'user_infos.user_id')
            ->whereRaw('users.id BETWEEN 1 AND ' . $total . '');
        //User::inRandomOrder()->toSql()->offset($offset)->limit($limit)->get();
        if ($filter != null) {
            //$where = 'user_infos.country = "'.$country.'"
            $where = ' user_infos.user_id != ' . $currentUser . '';
            if ($showMe != NULL) {
                $where .= ' AND user_infos.gender = "' . $showMe . '"';
            }
            if (isset($ageExp[0]) && isset($ageExp[1])) {
                $where .= ' AND age BETWEEN ' . $ageExp[0] . ' AND ' . $ageExp[1] . '';
            }
            $users->whereRaw($where);
        } else {
            $currentUser = User::find($currentUser);
            $userInfo = $currentUser->info;
            $where = ' user_infos.gender != "' . $userInfo['gender'] . '"';
            $users->whereRaw($where);
        }
        //dd($users->tosql());
        $users->inRandomOrder();
        $totalCount = $users->get()->count();
        $usersDetails = $users->limit($limit)->offset($offset)->get();
        //dd($usersDetails);
        try {
            $userDetails = [];
            foreach ($usersDetails as $user) {
                $LikedUser = DB::table('matches')->where('user_one', $reqData['user_id'])->where('user_two', $user->user_id)->get();
                $liked = (count($LikedUser));

                $DislikedUser = DB::table('dislikes')->where('user_one', $reqData['user_id'])->where('user_two', $user->user_id)->get();
                $disLiked = (count($DislikedUser));

                $para = (object) ['user_id' => $user->user_id];
                $response = Http::post('https://kkrinsi.com/datingApp/public/api/get-user-details', array($para));
                $jsonRes = $response->json();
                if (isset($jsonRes['data']['id'])) {
                    $matchPerc = self::getMatchPercentage($currentUser, $jsonRes['data']['id']);

                    foreach ($jsonRes["data"] as $key => $data) {
                        $jsonRes["data"][$key] = $data;
                        $jsonRes["data"]['match'] = $matchPerc;
                        $jsonRes["data"]['liked'] = ($liked == 0) ? false : true;
                        $jsonRes["data"]['disliked'] = ($disLiked == 0) ? false : true;
                    }
                    // print_r($jsonRes["data"]);
                    // exit;
                    // $newMatch = array("Match"=>$matchPerc);
                    // array_push($jsonRes["data"],$newMatch);
                    //dd($jsonRes["data"]);
                    $userDetails[] = $jsonRes["data"];
                }
            }
            return response()->json(['success' => true, 'data' => $userDetails, 'total' => $totalCount]);
        } catch (\Exception $e) {
            dd($e);
            return response()->json(['success' => false, 'errors' => [$e->getMessage()]], 200);
        }
    }

    public function updateUserProfile(Request $request)
    {
        $reqData = request()->all();
        $reqData = json_decode($reqData['data']);
        $rules = [
            'firstname' => ['required', 'string', 'min:3', 'max:255'],
            'lastname' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'max:8', 'unique:user_infos'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'age' => ['required', 'int', 'min:18', 'max:100'],
            'gender' => ['required'],
            'description' => ['required', 'min:10', 'max:255'],
            'relationship' => ['required'],
            'country' => ['required'],
            'languages' => ['required', 'min:2', 'max:255'],
            'search_age_range' => ['required'],
            'search_male' => ['required_unless:search_female,1'],
            'search_female' => ['required_unless:search_male,1']
        ];
        //dd($reqData);
        // $validator = Validator::make($reqData, $rules);
        // $checkExist = User::where('email',$reqData['email'])->get();
        // if(count($checkExist) > 0){
        //     return response()->json(['success' => false, 'errors' => ['Email address already exist.']], 200);
        // }
        $user = User::find($reqData->userid);
        if (isset($user)) {
            DB::table('user_infos')
                ->where('user_id', $reqData->userid)
                ->update([
                    'name' => $reqData->name,
                    'age' => $reqData->age,
                    'gender' => $reqData->gender,
                    'description' => $reqData->description,
                    'relationship' => $reqData->relationship,
                    'country' => $reqData->country,
                    //'languages'=>$reqData->languages,
                    'religion' => $reqData->religion,
                    'ethnicity' => $reqData->ethnicity,
                    'marital_status' => $reqData->marital_status,
                    'children' => $reqData->children,
                    'want_children' => $reqData->want_children,
                    'drinks' => $reqData->drinks,
                    'smokes' => $reqData->smokes,
                    'profession' => $reqData->profession,
                    //'interests'=>$reqData['interests'],
                    'DOB' => $reqData->DOB,
                    'hobbies' => $reqData->hobbies,
                    'latitude' => $reqData->latitude,
                    'longitude' => $reqData->longitude,
                    'location' => $reqData->location,
                    'here_for' => $reqData->here_for,
                    'height' => $reqData->height,
                    'body_type' => $reqData->body_type,
                    'updated_at' => now()
                ]);

            return response()->json(['success' => true, 'message' => ['User data updated successfully']], 200);
        } else {
            return response()->json(['success' => false, 'errors' => ['No user data found']], 200);
        }
    }

    public function getImagesList(Request $request)
    {
        $reqData = request()->all();
        $userId = $reqData['user_id'];
        if (isset($userId)) {
            $images = DB::table('pictures')->where('user_id', $userId)->get();
            $imgArr = [];
            foreach ($images as $image) {
                $imgArr[] = ['id' => $image->id, 'path' => $image->path];
            }

            return response()->json(['success' => true, 'data' => $imgArr], 200);
        }
    }

    public function removeImage(Request $request)
    {
        $reqData = request()->all();
        $imageIds = $reqData['image_id'];

        if (isset($imageIds)) {
            DB::table('pictures')->whereIn('id', $imageIds)->delete();
            return response()->json(['success' => true, 'message' => ['Profile image removed successfully']], 200);
        }
    }

    public function insertUserImages(Request $request)
    {
        $reqData = request()->all();
        $userId = $reqData['user_id'];
        $images = $request->file('img');

        if (isset($images)) {
            foreach ($images as $image) {
                $rand = substr(uniqid('', true), -5);
                $name = $rand . time() . '.' . $image->getClientOriginalExtension();
                $destinationPath = public_path('/images');
                $image->move($destinationPath, $name);
                $profilePic = URL::to('/') . '/images/' . $name;
                DB::table('pictures')->insert(['path' => $profilePic, 'user_id' => $userId]);
            }
            return response()->json(['success' => true, 'data' => $profilePic, 'message' => ['Update User images successfully']], 200);
        } else {
            return response()->json(['success' => false, 'message' => ['Invalid image.']], 200);
        }
    }

    public function updateUserPicture(Request $request)
    {
        $reqData = request()->all();
        $userId = $reqData['user_id'];
        $profilePicture = $request->file('profile_picture');

        if (isset($profilePicture)) {
            $rand = substr(uniqid('', true), -5);
            $name = $rand . time() . '.' . $profilePicture->getClientOriginalExtension();
            $destinationPath = public_path('/images');
            $profilePicture->move($destinationPath, $name);
            $profilePic = URL::to('/') . '/images/' . $name;
            DB::table('user_infos')->where('user_id', $userId)->update(['profile_picture' => $profilePic]);
            return response()->json(['success' => true, 'data' => $profilePic, 'message' => ['Update User profile image successfully']], 200);
        } else {
            return response()->json(['success' => false, 'message' => ['Invalid image.']], 200);
        }
    }

    public function getUserLocation(Request $request)
    {
        $reqData = json_decode(request()->getContent(), true);
        //dd($reqData);
        $userId = $reqData['user_id'];
        $user = User::find($userId);
        if ($user == null) {
            return response()->json(['success' => false, 'errors' => ['User not found']], 200);
        }
        //$location = $reqData['latitude'].';'.$reqData['longitude'];

        $updateLocation = UserInfo::where('user_id', $userId)->update(['latitude' => $reqData['longitude'], 'longitude' => $reqData['longitude']]);
        return response()->json(['success' => true, 'message' => "User Location updated successfully."]);
    }

    public function addPictures(AddUserPicturesRequest $request)
    {
        $reqData = json_decode(request()->getContent(), true);
        //dd($reqData);
        $userId = $reqData['user_id'];
        $user = User::find($userId);
        if ($user == null) {
            return response()->json(['success' => false, 'errors' => ['User not found']], 204);
        }

        if ($request->hasFile('picture')) {
            foreach ($request->file('picture') as $picture) {
                Picture::create([
                    'user_id' => $userId,
                    'path' => $picture->store('profilePictures', 'public')
                ]);
            }
            return response()->json(['success' => true, 'message' => "User Location updated successfully."]);
        }
    }

    public function getNearUserList(Request $request)
    {
        $reqData = json_decode(request()->getContent(), true);
        $userId = $reqData['user_id'];
        $page = $reqData['page'];
        $offset = $reqData['offset'];

        $limit = 10;
        if ($page == 1) {
            $offset = 0;
        } else {
            $page = (int)$page - 1;
            $offset = $page . "1";
        }

        $user = User::find($userId);
        $userSettings = $user->settings;
        $userInfo = $user->info;

        $lat = $userInfo->latitude;
        $long = $userInfo->longitude;
        $filter = isset($reqData['filter']) ? $reqData['filter'] : null;
        if ($filter != null) {
            $ageRange = isset($filter['age_range']) ? $filter['age_range'] : NULL;
            $distance = isset($filter['distance']) ? $filter['distance'] : 100;
            //$country = isset($filter['country']) ? strtolower($filter['country']) : NULL;
            $showMe = isset($filter['show_me']) ? strtolower($filter['show_me']) : NULL;
            if ($ageRange != NULL) {
                $ageExp = explode('-', $ageRange);
            } else {
                $ageExp = [];
            }
        }


        try {
            $nearestList = DB::raw(
                'SELECT ROUND(6371 * acos (cos ( radians(' . $lat . ') ) * cos( radians( latitude ) ) * cos( radians( longitude ) - radians(' . $long . ') ) + sin ( radians(' . $lat . ') ) * sin( radians( latitude ) ))) AS distance, user_infos.* FROM user_infos'
            );

            if ($filter != null) {
                //$where = ' WHERE user_infos.country = "'.$country.'"
                $where = ' WHERE user_infos.user_id != ' . $userId . '';
                if ($showMe != NULL) {
                    $where .= ' AND user_infos.gender = "' . $showMe . '"';
                }
                if (isset($ageExp[0]) && isset($ageExp[1])) {
                    $where .= ' AND age BETWEEN ' . $ageExp[0] . ' AND ' . $ageExp[1] . '';
                }
                $where .= ' HAVING distance <= ' . $distance;
            } else {
                $nearestList .= ' WHERE user_id != ' . $userId;
                $nearestList .= ' AND user_infos.gender != "' . $userInfo->gender . '"';
            }

            //$nearestList = $nearestList.$where;

            $totalCount = count(DB::select($nearestList));
            $nearestList .= ' limit ' . $limit . ' offset ' . $offset;

            $nearestList = DB::select($nearestList);

            // match percentage start
            /*
            $matchUsers = DB::table('users')
            ->leftJoin('user_infos', 'user_infos.user_id', '=', 'users.id')
            ->where('user_infos.country',$userInfo['country'])
            ->where('user_infos.ethnicity',$userInfo['ethnicity'])
            ->whereIn('user_infos.here_for',explode(',',$userInfo['here_for']))
            ->whereIn('user_infos.hobbies',explode(',',$userInfo['hobbies']))
            ->where('user_infos.marital_status',$userInfo['marital_status'])
            ->where('user_infos.user_id','!=',$userId);
            if($filter != null){
                $matchUsers->whereBetween('user_infos.age', [$ageExp[0], $ageExp[1]]);
                //HAVING distance <= '.$distance;
            }
            $totalRecords = $matchUsers->count();
            $matchUsers = $matchUsers->limit($limit)->offset($offset)->get();
            //dd($matchUsers);
            $matchPercentage = 0;
            $userObj = [];
            foreach($matchUsers as $user){
                //dd($userInfo['relationship']);
                if($user->gender != $userInfo['gender']){
                    $matchPercentage += 10;     
                }
                if($user->relationship == $userInfo['relationship']){
                    $matchPercentage += 10;     
                }
                if($user->country == $userInfo['country']){
                    $matchPercentage += 10;
                }
                if($user->languages == $userInfo['languages']){
                    $matchPercentage += 10;
                }
                if($user->religion == $userInfo['religion']){
                    $matchPercentage += 10;
                }
                if($user->ethnicity == $userInfo['ethnicity']){
                    $matchPercentage += 5;
                }
                if($user->marital_status != $userInfo['marital_status']){
                    $matchPercentage += 5;
                }
                if($user->children == $userInfo['children']){
                    $matchPercentage += 5;
                }
                if($user->want_children == $userInfo['want_children']){
                    $matchPercentage += 5;
                }
                if($user->drinks == $userInfo['drinks']){
                    $matchPercentage += 5;
                }
                if($user->smokes == $userInfo['smokes']){
                    $matchPercentage += 5;
                }
                if($user->profession == $userInfo['profession']){
                    $matchPercentage += 5;
                }
                if($user->interests == $userInfo['interests']){
                    $matchPercentage += 5;
                }
                if($user->hobbies == $userInfo['hobbies']){
                    $matchPercentage += 5;
                }
                if($user->here_for == $userInfo['here_for']){
                    $matchPercentage += 5;
                }
            }
            */
            $nearUserObj = [];
            if (!empty($nearestList)) {
                foreach ($nearestList as $list) {
                    $LikedUser = DB::table('matches')->where('user_one', $userId)->where('user_two', $list->user_id)->get();
                    $liked = (count($LikedUser));

                    $DislikedUser = DB::table('dislikes')->where('user_one', $userId)->where('user_two', $list->user_id)->get();
                    $disLiked = (count($DislikedUser));

                    $images = Picture::where('user_id', $list->user_id)->get();
                    $imgArr = [];
                    if (count($images) > 0) {
                        foreach ($images as $img) {
                            $imgArr[] = asset($img['path']);
                        }
                    }

                    $matchPerc = self::getMatchPercentage($userId, $list->user_id);

                    $nearUserObj[] = [
                        'id' => $list->user_id,
                        'name' => $list->name,
                        'description' => $list->description,
                        'phone' => $list->phone,
                        'age' => $list->age,
                        'profile_image' => $list->profile_picture,
                        'profile_picture' => $imgArr, //asset('storage/'.$list->profile_picture),
                        'country' => $list->country,
                        'gender' => $list->gender,
                        'location' => $list->location,
                        'dob' => $list->DOB,
                        'relationship' => $list->relationship,
                        'here_for' => $list->here_for,
                        'hobbies' => $list->hobbies,
                        'marital_status' => $list->marital_status,
                        'profession' => $list->profession,
                        'religion' => $list->religion,
                        'ethnicity' => $list->ethnicity,
                        'drinks' => $list->drinks,
                        'smokes' => $list->smokes,
                        'children' => $list->children,
                        'want_children' => $list->want_children,
                        'body_type' => $list->body_type,
                        'looking_for' => ($userSettings['search_male'] == 0) ? 'Female' : 'Male',
                        'match' => $matchPerc,
                        'liked' => ($liked == 0) ? false : true,
                        'disliked' => ($disLiked == 0) ? false : true,
                        'height' => $list->height
                    ];
                }
            }
            // $nearUserObj = self::sortAssociativeArrayByKey($nearUserObj,'match','DESC');
            return response()->json(['success' => true, 'data' => $nearUserObj, 'total' => $totalCount]);
        } catch (Exception $e) {
            //dd($e);
            return response()->json(['success' => false, 'errors' => [$e->getMessage()]], 200);
        }
    }

    private function sortAssociativeArrayByKey($array, $key, $direction)
    {
        switch ($direction) {
            case "ASC":
                usort($array, function ($first, $second) use ($key) {
                    return $first[$key] <=> $second[$key];
                });
                break;
            case "DESC":
                usort($array, function ($first, $second) use ($key) {
                    return $second[$key] <=> $first[$key];
                });
                break;
            default:
                break;
        }
        return $array;
    }

    public function getLikedUser(Request $request)
    {
        $reqData = json_decode(request()->getContent(), true);
        $userId = $reqData['user_id'];
        $page = $reqData['page'];
        //$offset = $reqData['offset'];
        $limit = 10;
        if ($page == 1) {
            $offset = 0;
        } else {
            $page = (int)$page - 1;
            $offset = $page . "1";
        }
        $user = User::find($userId);
        if ($user == null) {
            return response()->json(['success' => false, 'errors' => ['User not found']], 204);
        }
        $totalCount = DB::table('matches')->where('user_one', $userId)->get()->count();
        $getLikedUsers = DB::table('matches')->where('user_one', $userId)->limit($limit)->offset($offset)->pluck('user_two');
        //dd($getLikedUsers);
        $userObj = [];
        foreach ($getLikedUsers as $likeUser) {
            $images = Picture::where('user_id', $likeUser)->get();
            if (count($images) > 0) {
                $imgArr = [];
                foreach ($images as $img) {
                    $imgArr[] = asset($img['path']);
                }
            } else {
                $imgArr = [];
            }
            $likedUser = User::find($likeUser);
            $likeUserInfo = $likedUser->info;
            $userSettings = $likedUser->settings;
            $matchPerc = self::getMatchPercentage($userId, $likeUserInfo['user_id']);

            $userObj[] = [
                'id' => $likeUserInfo['user_id'],
                'name' => $likeUserInfo['name'],
                //'surname'=>$likeUserInfo['surname'],			
                'phone' => $likeUserInfo['phone'],
                'age' => isset($likeUserInfo['age']) ? $likeUserInfo['age'] : "",
                'gender' => $likeUserInfo['gender'],
                'profile_image' => $likeUserInfo['profile_picture'],
                'profile_picture' => $imgArr,
                'description' => $likeUserInfo['description'],
                'relationship' => $likeUserInfo['relationship'],
                'country' => $likeUserInfo['country'],
                'location' => $likeUserInfo['location'],
                'religion' => $likeUserInfo['religion'],
                'ethnicity' => $likeUserInfo['ethnicity'],
                'marital_status' => $likeUserInfo['marital_status'],
                'children' => ($likeUserInfo['children'] == 0) ? 'No' : 'Yes',
                'want_children' => ($likeUserInfo['want_children'] == 0) ? 'No' : 'Yes',
                'drinks' => ($likeUserInfo['drinks'] == 0) ? 'No' : 'Yes', 'smokes' => ($likeUserInfo['smokes'] == 0) ? 'No' : 'Yes',
                'profession' => $likeUserInfo['profession'],
                'hobbies' => $likeUserInfo['hobbies'],
                //'interests'=>$likeUserInfo['interests'],
                'here_for' => $likeUserInfo['here_for'],
                'looking_for' => ($userSettings['search_male'] == 0) ? 'Female' : 'Male',
                'height' => $likeUserInfo['height'],
                'match' => $matchPerc,
                'body_type' => $likeUserInfo['body_type']
            ];
        }
        return response()->json(['success' => true, 'data' => $userObj, 'total' => $totalCount]);
    }

    public function getLikesUser(Request $request)
    {
        $reqData = json_decode(request()->getContent(), true);
        $userId = $reqData['user_id'];
        $page = $reqData['page'];
        $offset = $reqData['offset'];
        $limit = 10;
        if ($page == 1) {
            $offset = 0;
        } else {
            $page = (int)$page - 1;
            $offset = $page . "1";
        }
        $user = User::find($userId);
        if ($user == null) {
            return response()->json(['success' => false, 'errors' => ['User not found']], 204);
        }
        $totalCount = DB::table('matches')->where('user_two', $userId)->groupBy('user_one')->get()->count();
        $getLikedUsers = DB::table('matches')->where('user_two', $userId)->limit($limit)->offset($offset)->groupBy('user_one')->pluck('user_one');

        $userObj = [];
        foreach ($getLikedUsers as $likeUser) {
            $images = Picture::where('user_id', $likeUser)->get();
            if (count($images) > 0) {
                $imgArr = [];
                foreach ($images as $img) {
                    $imgArr[] = asset($img['path']);
                }
            } else {
                $imgArr = [];
            }

            $likedUser = User::find($likeUser);
            $likeUserInfo = $likedUser->info;
            $userSettings = $likedUser->settings;

            $matchPerc = self::getMatchPercentage($userId, $likeUserInfo['user_id']);

            $userObj[] = [
                'id' => $likeUserInfo['user_id'],
                'name' => $likeUserInfo['name'],
                //'surname'=>$likeUserInfo['surname'],			
                'phone' => $likeUserInfo['phone'],
                'age' => isset($likeUserInfo['age']) ? $likeUserInfo['age'] : "",
                'gender' => $likeUserInfo['gender'],
                'profile_image' => $likeUserInfo['profile_picture'],
                'profile_picture' => $imgArr,
                'description' => $likeUserInfo['description'],
                'relationship' => $likeUserInfo['relationship'],
                'country' => $likeUserInfo['country'],
                'location' => $likeUserInfo['location'],
                'religion' => $likeUserInfo['religion'],
                'ethnicity' => $likeUserInfo['ethnicity'],
                'marital_status' => $likeUserInfo['marital_status'],
                'children' => ($likeUserInfo['children'] == 0) ? 'No' : 'Yes',
                'want_children' => ($likeUserInfo['want_children'] == 0) ? 'No' : 'Yes',
                'drinks' => ($likeUserInfo['drinks'] == 0) ? 'No' : 'Yes', 'smokes' => ($likeUserInfo['smokes'] == 0) ? 'No' : 'Yes',
                'profession' => $likeUserInfo['profession'],
                'hobbies' => $likeUserInfo['hobbies'],
                'interests' => $likeUserInfo['interests'],
                'here_for' => $likeUserInfo['here_for'],
                'looking_for' => ($userSettings['search_male'] == 0) ? 'Female' : 'Male',
                'height' => $likeUserInfo['height'],
                'match' => $matchPerc,
                'body_type' => $likeUserInfo['body_type']
            ];
        }
        return response()->json(['success' => true, 'data' => $userObj, 'total' => $totalCount]);
    }

    public function getUserDetails(Request $request)
    {
        $reqData = json_decode(request()->getContent(), true);
        if (isset($reqData[0])) {
            $userId = ((int)$reqData[0]['user_id']);
        }
        if (!isset($userId)) {
            $userId = $reqData['user_id'];
        }

        $user = User::find($userId);
        if ($user == null) {
            return response()->json(['success' => false, 'errors' => ['User not found']], 204);
        }
        try {
            $images = Picture::where('user_id', $userId)->get();
            if (count($images) > 0) {
                $imgArr = [];
                foreach ($images as $img) {
                    $imgArr[] = asset($img['path']);
                }
            } else {
                $imgArr = [];
            }

            $userInfo = $user->info;
            $userSetting = $user->settings;
            //dd($userInfo);		
            // $geocode=file_get_contents('http://maps.googleapis.com/maps/api/geocode/json?latlng='.$userInfo['latitude'].','.$userInfo['longitude'].'&sensor=false');
            // $output= json_decode($geocode);		
            // $currentLocation = isset($output->results[0]->formatted_address) ? $output->results[0]->formatted_address : "";
            $userObj = [
                'id' => $userInfo['user_id'],
                'name' => $userInfo['name'],
                'surname' => $userInfo['surname'],
                'phone' => $userInfo['phone'],
                'age' => isset($userInfo['age']) ? $userInfo['age'] : "",
                'DOB' => $userInfo['DOB'],
                'gender' => $userInfo['gender'],
                'profile_image' => $userInfo['profile_picture'],
                'profile_picture' => $imgArr, //asset('storage/'.$userInfo['profile_picture']),
                'description' => $userInfo['description'],
                'relationship' => $userInfo['relationship'],
                'country' => $userInfo['country'],
                'location' => $userInfo['location'],
                'ethnicity' => $userInfo['ethnicity'],
                'religion' => $userInfo['religion'],
                'latitude' => $userInfo['latitude'],
                'longitude' => $userInfo['longitude'],
                'marital_status' => $userInfo['marital_status'],
                'children' => ($userInfo['children'] == 0) ? 'No' : 'Yes',
                'want_children' => ($userInfo['want_children'] == 0) ? 'No' : 'Yes',
                'drinks' => ($userInfo['drinks'] == 0) ? 'No' : 'Yes', 'smokes' => ($userInfo['smokes'] == 0) ? 'No' : 'Yes',
                'profession' => $userInfo['profession'],
                'hobbies' => $userInfo['hobbies'],
                'interests' => $userInfo['interests'],
                'here_for' => $userInfo['here_for'],
                'looking_for' => ($userSetting['search_male'] == 0) ? 'Female' : 'Male',
                'height' => $userInfo['height'],
                'body_type' => $userInfo['body_type']
            ];
            return response()->json(['success' => true, 'data' => $userObj]);
        } catch (Exception $e) {
            //dd($e);
            return response()->json(['success' => false, 'errors' => [$e->getMessage()]], 204);
        }
    }

    public function like(Request $request)
    {
        $reqData = json_decode(request()->getContent(), true);
        $userId = $reqData['user_id'];
        $likedUserId = $reqData['liked_user_id'];
        $user = User::find($userId);
        $otherUser = User::find($likedUserId);
        $checkLikes = DB::table('matches')->where('user_one', $userId)->where('user_two', $likedUserId)->get();
        if (count($checkLikes) == 0) {
            $liked = $this->reactionService->like($user, $otherUser);

            $removeDisLikes = DB::table('dislikes')->where('user_one', $userId)->where('user_two', $likedUserId)->delete();

            return response()->json(['success' => true, 'message' => "You have Liked successfully."]);
        } else {
            return response()->json(['success' => true, 'message' => "User Not found."]);
        }
    }

    public function dislike(Request $request)
    {
        $reqData = json_decode(request()->getContent(), true);
        $userId = $reqData['user_id'];
        $likedUserId = $reqData['liked_user_id'];
        $user = User::find($userId);
        $otherUser = User::find($likedUserId);
        try {
            $checkDisLikes = DB::table('dislikes')->where('user_one', $userId)->where('user_two', $likedUserId)->get();
            if (count($checkDisLikes) == 0) {
                $this->reactionService->dislike($user, $otherUser);

                $removeLikes = DB::table('matches')->where('user_one', $userId)->where('user_two', $likedUserId)->delete();

                if ($removeLikes) {
                    return response()->json(['success' => true, 'message' => "You have disliked."]);
                }
            } else {
                return response()->json(['success' => true, 'message' => "User not found."]);
            }
        } catch (Exception $e) {
            return response()->json(['success' => false, 'errors' => [$e->getMessage()]], 204);
        }
    }

    public function getMatchUser(Request $request)
    {
        $reqData = json_decode(request()->getContent(), true);
        $userId = $reqData['user_id'];
        $page = $reqData['page'];
        $limit = 10;
        if ($page == 1) {
            $offset = 0;
        } else {
            $page = (int)$page - 1;
            $offset = $page . "1";
        }
        $filter = isset($reqData['filter']) ? $reqData['filter'] : null;
        if ($filter != null) {
            $ageRange = isset($filter['age_range']) ? $filter['age_range'] : NULL;
            $distance = isset($filter['distance']) ? $filter['distance'] : 100;
            //$country = isset($filter['country']) ? strtolower($filter['country']) : NULL;
            $showMe = isset($filter['show_me']) ? strtoupper($filter['show_me']) : NULL;
            if ($ageRange != NULL) {
                $ageExp = explode('-', $ageRange);
            }
        }
        //$users = User::searchMatches($userId)->get();
        $user = User::find($userId);

        //if(count($user) > 0){
        $userSettings = $user->settings;
        $userInfo = $user->info;
        //dd(explode(',',$userInfo['here_for']));
        $matchUsers = DB::table('users')
            ->leftJoin('user_infos', 'user_infos.user_id', '=', 'users.id')
            //->where('user_infos.country',$userInfo['country'])
            //->where('user_infos.ethnicity',$userInfo['ethnicity'])
            //->whereIn('user_infos.here_for',explode(',',$userInfo['here_for']))
            //->whereIn('user_infos.hobbies',explode(',',$userInfo['hobbies']))
            ->where('user_infos.user_id', '!=', $userId);

        if ($filter != null) {
            $matchUsers->whereBetween('user_infos.age', [$ageExp[0], $ageExp[1]]);
            if ($showMe != NULL) {
                $matchUsers->where('user_infos.gender', $showMe);
            }
            //HAVING distance <= '.$distance;
        } else {
            $matchUsers->where('user_infos.gender', "!=", strtoupper($userInfo['gender']));
        }

        $totalRecords = $matchUsers->count();
        $matchUsers = $matchUsers->limit($limit)->offset($offset)->get();
        //dd($matchUsers);
        $userObj = [];
        foreach ($matchUsers as $user) {
            //dd($userInfo['relationship']);
            $matchPercentage = 0;
            if ($user->gender != $userInfo['gender']) {
                $matchPercentage += 10;
            }
            if ($user->relationship == $userInfo['relationship']) {
                $matchPercentage += 10;
            }
            if ($user->country == $userInfo['country']) {
                $matchPercentage += 10;
            }
            if ($user->languages == $userInfo['languages']) {
                $matchPercentage += 10;
            }
            if ($user->religion == $userInfo['religion']) {
                $matchPercentage += 10;
            }
            if ($user->ethnicity == $userInfo['ethnicity']) {
                $matchPercentage += 5;
            }
            if ($user->marital_status != $userInfo['marital_status']) {
                $matchPercentage += 5;
            }
            if ($user->children == $userInfo['children']) {
                $matchPercentage += 5;
            }
            if ($user->want_children == $userInfo['want_children']) {
                $matchPercentage += 5;
            }
            if ($user->drinks == $userInfo['drinks']) {
                $matchPercentage += 5;
            }
            if ($user->smokes == $userInfo['smokes']) {
                $matchPercentage += 5;
            }
            if ($user->profession == $userInfo['profession']) {
                $matchPercentage += 5;
            }
            if ($user->interests == $userInfo['interests']) {
                $matchPercentage += 5;
            }
            if ($user->hobbies == $userInfo['hobbies']) {
                $matchPercentage += 5;
            }
            if ($user->here_for == $userInfo['here_for']) {
                $matchPercentage += 5;
            }
            //dd($matchPercentage);
            $images = Picture::where('user_id', $user->id)->get();
            if (count($images) > 0) {
                $imgArr = [];
                foreach ($images as $img) {
                    $imgArr[] = asset($img['path']);
                }
            } else {
                $imgArr = [];
            }

            // $otherUser = $this->randomUserService->getUser($user, $userSettings);
            // $matchUserDetails = User::find($otherUser['id']);
            // $matchUserInfo = $matchUserDetails->info;
            if (isset($user)) {
                $LikedUser = DB::table('matches')->where('user_one', $userId)->where('user_two', $user->user_id)->get();
                $liked = (count($LikedUser));

                $DislikedUser = DB::table('dislikes')->where('user_one', $userId)->where('user_two', $user->user_id)->get();
                $disLiked = (count($DislikedUser));


                $userObj[] = [
                    'id' => $user->user_id,
                    'name' => $user->name,
                    //'surname'=>$matchUserInfo['surname'],			
                    'phone' => $user->phone,
                    'age' => isset($user->age) ? $user->age : "",
                    'gender' => $user->gender,
                    'profile_image' => $user->profile_picture,
                    'profile_picture' => $imgArr,
                    'description' => $user->description,
                    'relationship' => $user->relationship,
                    'country' => $user->country,
                    'location' => $user->location,
                    'ethnicity' => $user->ethnicity,
                    'religion' => $user->religion,
                    'hobbies' => $user->hobbies,
                    'marital_status' => $user->marital_status,
                    'children' => ($user->children == 0) ? 'No' : 'Yes',
                    'want_children' => ($user->want_children == 0) ? 'No' : 'Yes',
                    'drinks' => ($user->drinks == 0) ? 'No' : 'Yes', 'smokes' => ($user->smokes == 0) ? 'No' : 'Yes',
                    'profession' => $user->profession,
                    'interests' => $user->interests,
                    'here_for' => $user->here_for,
                    'looking_for' => ($userSettings['search_male'] == 0) ? 'Female' : 'Male',
                    'height' => $user->height,
                    'match' => $matchPercentage . '%',
                    'liked' => ($liked == 0) ? false : true,
                    'disliked' => ($disLiked == 0) ? false : true,
                    'body_type' => $user->body_type
                ];
                //return response()->json(['success' => true,'data' =>$userObj ]); 
            }
        }
        $userObj = self::sortAssociativeArrayByKey($userObj, "match", "DESC");
        return response()->json(['success' => true, 'data' => $userObj, 'total' => $totalRecords]);

        // }
        // else{
        //     response()->json(['success' => true,'message' =>"No data found",'status' => 200]);
        // }
    }


    public function send_message(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'sender_id' => 'required',
                'receiver_id' => 'required',
                'message' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => $validator->errors()->first()]);
            }


            $chat = new Chat();
            $chat->sender_id = $request->sender_id;
            $chat->receiver_id = $request->receiver_id;
            $chat->message = $request->message;
            $chat->save();

            $created_at = $chat->created_at;
            $updated_at = $chat->updated_at;
            unset($chat->created_at);
            unset($chat->updated_at);

            // $chat_update = $chat->only('id')
            $chat->create_at = $created_at ?  date('Y-m-d\TH:i:s', strtotime($created_at)) : null;
            $chat->update_at = $updated_at ?  date('Y-m-d\TH:i:s', strtotime($updated_at)) : null;
            //notification to device

            $receiver = User::whereNotNull('device_token')->where('id', $request->receiver_id)->first();
            if ($receiver) {
                $SERVER_API_KEY = env('FCM_SERVER_KEY');

                $data = [
                    "registration_ids" => [$receiver->device_token],
                    "notification" => [
                        "title" => 'New Message',
                        "body" => $request->message,
                    ]
                ];
                $dataString = json_encode($data);

                $headers = [
                    'Authorization: key=' . $SERVER_API_KEY,
                    'Content-Type: application/json',
                ];

                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);

                $response = curl_exec($ch);
            }
            //END Notification 




            return response()->json(['success' => true, 'message' => "Message Sent Successfully.", 'data' => ["message" => $chat]]);
        } catch (Exception $error) {
            return response()->json(['success' => false, 'message' => "Something is Wrong."]);
        }
    }

    public function saveToken(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'login_user_id' => 'required',
        ]);


        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()]);
        }

        $user = User::find($request->login_user_id);
        if (!$user) return response()->json(['success' => false, 'message' => "user not found"]);
        $user->update(['device_token' => $request->token]);
        return response()->json(['success' => true, 'message' => "token saved successfully.", 'data' => []]);
    }

    public function chat_users_list(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'login_user_id' => 'required',
                'page_no' => 'sometimes|nullable|numeric'
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => $validator->errors()->first()]);
            }

            $login_user_id = $request->login_user_id;

            $chat_receiver_ids = Chat::where('sender_id', $login_user_id)->groupBy('receiver_id')->pluck('receiver_id')->toArray();
            $chat_sender_ids = Chat::where('receiver_id', $login_user_id)->groupBy('sender_id')->pluck('sender_id')->toArray();

            $user_ids = array_unique(array_merge($chat_receiver_ids, $chat_sender_ids), SORT_REGULAR);

            $users = User::select('user_infos.name', 'users.id', 'user_infos.profile_picture')->whereIn('users.id', $user_ids)
                ->leftJoin('user_infos', 'user_infos.user_id', 'users.id');
            if (isset($request->page_no) && !empty($request->page_no)) {
                $limit = 10;
                $start = (($request->page_no - 1) * $limit);
                $users = $users->limit($limit)->offset($start);
            }
            $users = $users->get();

            $user_list = [];
            foreach ($users as $user) {
                $chat = Chat::select('created_at', 'message')->where('sender_id', $login_user_id)->orWhere('receiver_id', $login_user_id)->latest()->first();
                $total_chat_count = Chat::select('created_at', 'message')->where('sender_id', $login_user_id)->orWhere('receiver_id', $login_user_id)->count();
                $read_chat_count = Chat::select('created_at', 'message')->where(function ($qeury) use ($login_user_id) {
                    return $qeury->where('sender_id', $login_user_id)->orWhere('receiver_id', $login_user_id);
                })->where('is_read', '1')->count();
                $unread_chat_count = Chat::select('created_at', 'message')->where(function ($qeury) use ($login_user_id) {
                    return $qeury->where('sender_id', $login_user_id)->orWhere('receiver_id', $login_user_id);
                })->where('is_read', '0')->count();

                $user->last_message = $chat ? $chat->message : null;
                $user->total_chat_count = $total_chat_count;
                $user->read_chat_count = $read_chat_count;
                $user->unread_chat_count = $unread_chat_count;
                $user->last_message_time_stamp = $chat ? date('Y-m-d\TH:i:s', strtotime($chat->created_at)) : null;
                $user_list[] = $user;
            }

            return response()->json(['success' => true, 'message' => "Chat list fetch successfully.", 'data' => ['user_list' => $user_list]]);
        } catch (Exception $error) {
            return response()->json(['success' => false, 'message' => "Something is Wrong."]);
        }
    }


    public function user_chats_list(Request $request)
    {
        // try {
        $validator = Validator::make($request->all(), [
            'login_user_id' => 'required',
            'chat_user_id' => 'required',
            'page_no' => 'sometimes|nullable|numeric'
        ]);

        $login_user_id = $request->login_user_id;
        $chat_user_id = $request->chat_user_id;

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()]);
        }

        $chat_list = Chat::select('sender_id', 'receiver_id', 'message', 'is_read')->selectRaw('DATE_FORMAT(created_at,"%Y-%m-%dT%H:%i:%s") as message_time')->whereIn('sender_id', [$login_user_id, $chat_user_id])->whereIn('receiver_id', [$login_user_id, $chat_user_id]);
        // $chat_list = Chat::select('sender_id', 'receiver_id', 'message', 'is_read')->selectRaw("cast(`created_at as message_time) at time zone 'UTC'")->whereIn('sender_id', [$login_user_id, $chat_user_id])->whereIn('receiver_id', [$login_user_id, $chat_user_id]);
        if (isset($request->page_no) && !empty($request->page_no)) {
            $limit = 10;
            $start = (($request->page_no - 1) * $limit);
            $chat_list = $chat_list->limit($limit)->offset($start);
        }
        $chat_list = $chat_list->latest()->get();


        Chat::where('sender_id', $chat_user_id)->Where('receiver_id', $login_user_id)->update(['is_read' => '1']);



        return response()->json(['success' => true, 'message' => "Chat User list fetch successfully.", 'data' => ['user_chat_list' => $chat_list]]);
        // } catch (Exception $error) {
        //     return response()->json(['success' => false, 'message' => "Something is Wrong."]);
        // }
    }
}

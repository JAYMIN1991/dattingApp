<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use App\Services\EmojiService;
use App\Services\RandomUserService;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use Validator;
use App\User;
use App\UserInfo;
use Illuminate\Support\Facades\URL;
use App\UserSettings;
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

    public function __construct(EmojiService $emojiService, RandomUserService $randomUserService)
    {
        //$this->middleware('auth');
        $this->emojiService = $emojiService;
        $this->randomUserService = $randomUserService;
    }
    public function login(Request $request)
    {
        $credentials = json_decode(request()->getContent(),true);
        $rules = [
            'email' => 'required|email'
            //'password' => 'required',
        ];
        $validator = Validator::make($credentials, $rules);
        $requiredKeys = ['email'];
        $newArray = array();
        foreach ($credentials as $key => $value) {
            if(in_array($key,$requiredKeys))
                $newArray[$key] = $credentials[$key];
        }
        $credentials = $newArray;
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
        }

        try {
            //Check account available or not
            $findUser = User::where('email',$credentials['email'])->first();
            if($findUser == null){
                return response()->json(['success' => false, 'errors' => ['We cant find an account with this credentials.']], 401);
            }
            // attempt to verify the credentials and create a token for the user
            if (!$token = JWTAuth::fromUser($findUser)) {
                User::where('email',$credentials['email']);
                return response()->json(['success' => false, 'errors' => ['Invalid credentials. Please try again']], 401);
            }
        } catch (JWTException $e) {
            // something went wrong whilst attempting to encode the token
            return response()->json(['success' => false, 'errors' => [$e->getMessage()]], 500);
        }
        // all good so return the token

        if($findUser['is_verified'] != 1){
            return response()->json(['success' => false, 'errors' => ['Your account is still not activated yet. Kindly check your email.']], 500);
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
        return response()->json(['success' => true, 'data' => ['user'=>$userObj],'expires_in' => auth()->factory()->getTTL() * 600]);
    }

    public function logout(Request $request)
    {
        $this->validate($request, ['token' => 'required']);
        try {
            JWTAuth::invalidate($request->get('token'));
            return response()->json(['success' => true, 'message' => "You have successfully logged out."]);
        } catch (JWTException $e) {
            // something went wrong whilst attempting to encode the token
            return response()->json(['success' => false, 'errors' => ['Failed to logout, please try again.']], 500);
        }
    }

    public function register(Request $request){
        $reqData = json_decode(request()->getContent(),true);
        $rules = [
            'firstname' => ['required', 'string', 'min:3', 'max:255'],
            'lastname' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'max:8', 'unique:user_infos'],
            'password' => ['required', 'string', 'min:8', 'required_with:password_confirmation|same:password_confirmation'],
            'age' => ['required', 'int', 'min:18', 'max:100'],
            'gender' => ['required'],
            'description' => ['required', 'min:10', 'max:255'],
            'relationship' => ['required'],
            'img' => ['required'],
            'country' => ['required'],
            'languages' => ['required', 'min:2', 'max:255'],
            'search_age_range' => ['required'],
            'search_male' => ['required_unless:search_female,1'],
            'search_female' => ['required_unless:search_male,1'],
            'religion' => ['required'],
            'marital_status' => ['required'],
            'children' => ['required'],
            'want_children' => ['required'],
            'drinks' => ['required'],
            'smokes' => ['required'],
            'prefession' => ['required'],
            'interests' => ['required'],
            'DOB' => ['required'],
            //'location' => ['required'],
            'here_for' => ['required'],
            'height' => ['required'],
            'body_type' => ['required']
        ];
        //dd($reqData);
        $validator = Validator::make($reqData, $rules);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
        }
        $checkExist = User::where('email',$reqData['email'])->get();
        if(count($checkExist) > 0){
            return response()->json(['success' => false, 'errors' => ['Email address already exist.']], 500);
        }
        $imgName = self::base64_to_jpeg($reqData['img'],'output.jpg');
        dd($imgName);
        try{
            DB::beginTransaction();

            $user = User::create([
                'email' => $reqData['email'],
                'password' => Hash::make($reqData['password'])
            ]);

            UserInfo::create([
                'user_id' => $user->id,
                'name' => $reqData['firstname'],
                'surname' => $reqData['lastname'],
                'phone' => $reqData['phone'],
                'age' => $reqData['age'],
                'gender' => $reqData['gender'],
                'profile_picture' => '',
                'description' => $reqData['description'],
                'relationship' => $reqData['relationship'],
                'country' => $reqData['country'],
                'languages' => $reqData['languages'],
                'religion' => $reqData['religion'],
                'marital_status'=> $reqData['marital_status'],
                'children'=> $reqData['children'],
                'want_children'=> $reqData['want_children'],
                'drinks'=> $reqData['drinks'],
                'smokes'=> $reqData['smokes'],
                'prefession'=> $reqData['prefession'],
                'interests'=> $reqData['interests'],
                'DOB'=> $reqData['DOB'],
                //'location'=> $reqData['location'],
                'here_for'=> $reqData['here_for'],
                'height'=> $reqData['height'],
                'body_type'=> $reqData['body_type']
            ]);
    
            if ($request->hasFile('picture')) {
                foreach ($request->file('picture') as $picture) {
                    Picture::create([
                        'user_id' => $user->id,
                        'path' => $picture->store('profilePictures', 'public')
                    ]);
                }
                //return response()->json(['success' => true, 'message' => "User Location updated successfully."]);
            }

            $searchAgeRange = explode(';', $reqData['search_age_range']);
    
            (isset($reqData['search_male'])) ? $searchMale = 1 : $searchMale = 0;
            (isset($reqData['search_female'])) ? $searchFemale = 1 : $searchFemale = 0;
    
            UserSettings::create([
                'user_id' => $user->id,
                'search_age_from' => $searchAgeRange[0],
                'search_age_to' => $searchAgeRange[1],
                'search_male' => $searchMale,
                'search_female' => $searchFemale
            ]);
            DB::commit();
            return response()->json(['success' => true, 'message' => "You have registered successfully."]);
        }
        catch(\Exception $e){
            DB::rollback();
            return response()->json(['success' => false, 'errors' => [$e->getMessage()]], 500);
        }
    }

    private function base64_to_jpeg($base64_string, $output_file) {
        // open the output file for writing
        $ifp = fopen( $output_file, 'wb' ); 
    
        // split the string on commas
        // $data[ 0 ] == "data:image/png;base64"
        // $data[ 1 ] == <actual base64 string>
        $data = explode( ',', $base64_string );
        dd($data);
        // we could add validation here with ensuring count( $data ) > 1
        fwrite( $ifp, base64_decode( $data[ 1 ] ) );
    
        // clean up the file resource
        fclose( $ifp ); 
    
        return $output_file; 
    }
    

    public function getRandomUsers(Request $request)
    {
        $reqData = json_decode(request()->getContent(),true);
        $currentUser = $reqData['user_id'];
        $user = User::find($currentUser);
        //dd($user);
        // $user = auth()->user();
        // if($user == null){
        //     return response()->json(['success' => false, 'errors' => ['Login session has been expired.']], 500);
        // }
        $userSettings = $user->settings;

        $otherUser = $this->randomUserService->getUser($user, $userSettings);

        if ($otherUser == null) {
            $pictures = null;
        } else {
            $pictures = $otherUser->pictures;
        }

        return response()->json(['success' => true, 'message' => "random profile list",
                'data' => ['otherUser' => $otherUser,
                    'user' => $user,
                    'pictures' => $pictures,
                    'likeEmoji' => $this->emojiService->getPositiveEmojiUrl(),
                    'dislikeEmoji' => $this->emojiService->getNegativeEmojiUrl()
        ]]);
        // return view('home', [
        //     'otherUser' => $otherUser,
        //     'user' => $user,
        //     'pictures' => $pictures,
        //     'likeEmoji' => $this->emojiService->getPositiveEmojiUrl(),
        //     'dislikeEmoji' => $this->emojiService->getNegativeEmojiUrl()
        // ]);
    }

    public function updateUserProfile(Request $request){
        $reqData = json_decode(request()->getContent(),true);
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
        $validator = Validator::make($reqData, $rules);
        $checkExist = User::where('email',$reqData['email'])->get();
        if(count($checkExist) > 0){
            return response()->json(['success' => false, 'errors' => ['Email address already exist.']], 500);
        }
    }

    public function getUserLocation(Request $request){
        $reqData = json_decode(request()->getContent(),true);
        //dd($reqData);
        $userId = $reqData['user_id'];
        $user = User::find($userId);
        if($user == null){
            return response()->json(['success' => false, 'errors' => ['User not found']], 500);
        }
        $location = $reqData['latitude'].';'.$reqData['longitude'];
        
        $updateLocation = UserInfo::where('user_id',$userId)->update(['location'=>$location]);
        return response()->json(['success' => true, 'message' => "User Location updated successfully."]);   
    }

    public function addPictures(AddUserPicturesRequest $request)
    {
        $reqData = json_decode(request()->getContent(),true);
        //dd($reqData);
        $userId = $reqData['user_id'];
        $user = User::find($userId);
        if($user == null){
            return response()->json(['success' => false, 'errors' => ['User not found']], 500);
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
}

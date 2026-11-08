@extends('layouts.app')
@section('title', 'Publier des photos sur TikTok')
@section('content')
<div class="max-w-4xl mx-auto py-8 px-4" x-data="tiktokPhotos(@js($media))">
    <p class="mb-5 text-sm text-gray-600">Une photo ou un carrousel natif, dans l’ordre choisi. Jusqu’à 35 photos et 250 Mo au total. L’envoi passe par Companion sur le NAS.</p>
    <div class="bg-white border rounded-xl p-5 space-y-5">
        <label class="block">Compte TikTok
            <select x-model="account" @change="changed()" class="block w-full mt-1 rounded border-gray-300" :disabled="busy">
                <option value="">Choisir un compte</option>
                @foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach
            </select>
        </label>
        <ol class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <template x-for="(photo,index) in media" :key="photo.id"><li class="border rounded-lg p-2">
                <img :src="photo.url" alt="Photo sélectionnée" class="aspect-square w-full object-contain bg-gray-50 rounded">
                <div class="flex items-center justify-between mt-2"><span x-text="index+1"></span><div>
                    <button type="button" @click="move(index,-1)" :disabled="busy || index===0" class="px-2 disabled:opacity-30" aria-label="Avancer la photo">←</button>
                    <button type="button" @click="move(index,1)" :disabled="busy || index===media.length-1" class="px-2 disabled:opacity-30" aria-label="Reculer la photo">→</button>
                </div></div>
            </li></template>
        </ol>
        <label class="block">Titre (facultatif)<input x-model="title" @input="changed()" maxlength="90" class="block w-full mt-1 rounded border-gray-300" :disabled="busy"></label>
        <label class="block">Description<textarea x-model="caption" @input="changed()" maxlength="4000" rows="5" class="block w-full mt-1 rounded border-gray-300" :disabled="busy"></textarea></label>
        <label class="block">Visibilité<select x-model="visibility" @change="changed()" class="block w-full mt-1 rounded border-gray-300" :disabled="busy"><option value="public">Tout le monde</option><option value="private">Moi uniquement</option></select></label>
        <div class="flex flex-wrap gap-3">
            <button type="button" @click="submit('preview')" :disabled="busy" class="border rounded-lg px-4 py-2 disabled:opacity-40">Vérifier les médias</button>
            <button type="button" @click="submit('rehearse')" :disabled="busy" class="border rounded-lg px-4 py-2 disabled:opacity-40">Tester sur le NAS</button>
            <button type="button" @click="submit('publish')" :disabled="busy" class="bg-gray-900 text-white rounded-lg px-4 py-2 disabled:opacity-40">Publier</button>
        </div>
        <p role="status" aria-live="polite" class="text-sm whitespace-pre-line" x-text="message"></p>
        <a x-show="postUrl" :href="postUrl" target="_blank" rel="noopener" class="text-indigo-700 underline">Voir la publication TikTok</a>
        <p class="text-xs text-gray-500">Le test importe les photos et vérifie l’éditeur sans cliquer sur Publier. Une publication non confirmée reste à contrôler : ne pas la renvoyer.</p>
    </div>
</div>
<script>
function tiktokPhotos(media){return {media,account:'',title:'',caption:'',visibility:'public',busy:false,message:'',postUrl:'',key:crypto.randomUUID(),
changed(){if(!this.busy){this.key=crypto.randomUUID();this.message='';this.postUrl=''}},
move(i,d){[this.media[i],this.media[i+d]]=[this.media[i+d],this.media[i]];this.changed()},
async submit(mode){if(!this.account||!this.caption.trim()){this.message='Choisir le compte et renseigner la description.';return}this.busy=true;this.message='Vérification…';
try{const body={media_ids:this.media.map(m=>m.id),title:this.title,caption:this.caption,visibility:this.visibility,dry_run:mode==='preview',rehearse:mode==='rehearse',request_key:this.key};
const res=await fetch('/tiktok/accounts/'+this.account+'/photo-posts',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(body)});let data=await res.json();if(!res.ok)throw Error(data.message||Object.values(data.errors||{}).flat().join(' ')||'Demande refusée');
if(mode==='preview'){this.message=this.media.length+' photo(s) vérifiée(s).';this.busy=false;return}
this.message='Traitement sur le NAS…';const end=Date.now()+26*60*1000;while(['pending','running'].includes(data.status)&&Date.now()<end){await new Promise(r=>setTimeout(r,3000));const r=await fetch('/tiktok/photo-posts/'+data.id,{headers:{'Accept':'application/json'}});if(!r.ok)throw Error('Lecture interrompue. Conserver cette page et contrôler le journal.');data=await r.json()}
this.message=data.confirmed?'Publication confirmée après rechargement.':data.rehearsed?'Test réussi : photos et texte vérifiés, aucun clic sur Publier.':(data.error||'Résultat à contrôler, ne pas renvoyer.');this.postUrl=data.confirmed?data.result.url:'';
// Une répétition et une publication constituent deux intentions distinctes.
if(data.rehearsed)this.key=crypto.randomUUID();
}catch(e){this.message=e.message}finally{this.busy=false}}
}}
</script>
@endsection

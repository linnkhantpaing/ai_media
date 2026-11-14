export interface BrandingAsset {file:string;name:string;bytes:number;url:string}
export interface GeneralConfiguration {
 name:string;tagline:string;language:string;timezone:string;date_format:string;time_format:string;
 support_email:string;support_phone:string;support_url:string;
 main_logo:BrandingAsset|null;light_logo:BrandingAsset|null;favicon:BrandingAsset|null;
 smtp:{enabled:boolean;host:string;port:number;encryption:string;username:string;password:string;from_name:string;from_email:string;reply_to:string};
 maintenance:{enabled:boolean;message:string};
}
export interface MailReport {kind:'connection'|'email';status:'success'|'error';code:string|null;at:string}
export interface GeneralState {revision:number;saved_at:string|null;configuration:GeneralConfiguration;has_password:boolean;mail_report:Partial<Record<'connection'|'email',MailReport>>|null}

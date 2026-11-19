export type NotificationCategory='video'|'payment'|'plan'|'account';
export type NotificationActionKind='download'|'review'|'retry'|'renew'|'provide';
export type CustomerNotification={
 id:number;type:string;category:NotificationCategory;data:Record<string,string|number|null>;
 read:boolean;archived:boolean;removed:boolean;at:string;url:string|null;
 action:{kind:NotificationActionKind;url:string}|null;
};
export type NotificationFeed={unread:number;all:CustomerNotification[];unread_items:CustomerNotification[]};
export type NotificationFilters={tab:string;status:string;period:string;archived:boolean;q:string};
export type NotificationListing={
 items:CustomerNotification[];counts:Record<string,number>;unread:number;
 pagination:{page:number;pages:number;total:number;per_page:number};
 filters:NotificationFilters;endpoints:{index:string;action:string};
};

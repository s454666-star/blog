import threading
import os
import tkinter as tk
from tkinter import filedialog, messagebox
from PIL import Image, ImageTk
from customtkinter import CTk, CTkLabel, CTkButton, CTkProgressBar, CTkEntry, CTkOptionMenu
import numpy as np
import math
import cv2  # 用於影片讀取和處理
from mtcnn import MTCNN
import re
import traceback
import shutil
# import mysql.connector  # 不使用資料庫

class FaceExtractorApp:
    def __init__(self, root):
        self.root = root
        self.root.title("影片人臉擷取工具")
        self.root.geometry("800x600")

        # 設定風格
        self.root.configure(bg="#f0f0f0")

        # 設定變數
        self.video_list = []
        self.is_running = False
        self.current_index = 0

        # 標題
        CTkLabel(root, text="影片人臉擷取工具", font=("Arial", 20)).pack(pady=10)

        # 選擇影片按鈕
        self.select_button = CTkButton(root, text="選擇影片", command=self.select_videos)
        self.select_button.pack(pady=5)

        # 清單視窗
        self.video_listbox = tk.Listbox(root, height=10, width=80, selectmode=tk.MULTIPLE)
        self.video_listbox.pack(pady=10)
        self.video_listbox.bind("<Delete>", self.delete_selected_videos)

        # 擷取張數輸入框
        self.frame_count_label = CTkLabel(root, text="選擇擷取張數（1-1000）：")
        self.frame_count_label.pack(pady=5)
        self.frame_count_entry = CTkEntry(root, width=100, placeholder_text="10")
        self.frame_count_entry.pack(pady=5)

        # 新增影片類別下拉選單
        self.video_type_label = CTkLabel(root, text="影片類別：")
        self.video_type_label.pack(pady=5)
        self.video_type_var = tk.StringVar(value="1")  # 設定預設值為 "1"
        self.video_type_optionmenu = CTkOptionMenu(
            root,
            variable=self.video_type_var,
            values=["1", "2", "3", "4"]
        )
        self.video_type_optionmenu.pack(pady=5)

        # 進度顯示
        self.progress_label = CTkLabel(root, text="進度：0%")
        self.progress_label.pack(pady=5)
        self.current_file_label = CTkLabel(root, text="目前處理檔案：無")
        self.current_file_label.pack(pady=5)
        self.progress_bar = CTkProgressBar(root, width=400)
        self.progress_bar.set(0)
        self.progress_bar.pack(pady=10)

        # 按鈕區域
        button_frame = tk.Frame(root, bg="#f0f0f0")
        button_frame.pack(pady=10)

        self.start_button = CTkButton(button_frame, text="執行", command=self.start_extraction)
        self.start_button.pack(side=tk.LEFT, padx=20)

        self.pause_button = CTkButton(button_frame, text="暫停", command=self.pause_extraction, state='disabled')
        self.pause_button.pack(side=tk.LEFT, padx=20)

        self.stop_button = CTkButton(button_frame, text="停止", command=self.stop_extraction, state='disabled')
        self.stop_button.pack(side=tk.LEFT, padx=20)

        # 初始化 MTCNN 人臉偵測器
        self.detector = MTCNN()

        # 初始化資料庫連線
        # """
        # try:
        #     self.db_connection = mysql.connector.connect(
        #         host="mysql.mystar.monster",
        #         port=3306,
        #         user="s454666",
        #         password="i06180318",
        #         database="star"
        #     )
        #     self.db_cursor = self.db_connection.cursor()
        #     print("成功連接到資料庫")
        # except mysql.connector.Error as err:
        #     print(f"資料庫連線錯誤: {err}")
        #     messagebox.showerror("資料庫錯誤", f"無法連接到資料庫: {err}")
        #     self.db_connection = None
        #     self.db_cursor = None
        # """

    def sanitize_filename(self, filename):
        """移除檔名中的非法字符"""
        return re.sub(r'[<>:"/\\|?*]', '_', filename)

    def get_unique_output_dir(self, base_dir):
        """
        如果 base_dir 已存在，則新增 _1, _2 等後綴以產生唯一的目錄名稱
        """
        if not os.path.exists(base_dir):
            return base_dir
        counter = 1
        while True:
            new_dir = f"{base_dir}_{counter}"
            if not os.path.exists(new_dir):
                return new_dir
            counter += 1

    def select_videos(self):
        files = filedialog.askopenfilenames(title="選擇影片", filetypes=[("影片檔案", "*.mp4;*.avi;*.mov")])
        for file in files:
            if file not in self.video_list:
                self.video_list.append(file)
                self.video_listbox.insert(tk.END, os.path.basename(file))
        print(f"選擇的影片列表: {self.video_list}")

    def delete_selected_videos(self, event=None):
        selected_indices = self.video_listbox.curselection()
        for index in selected_indices[::-1]:
            print(f"刪除影片: {self.video_list[index]}")
            self.video_listbox.delete(index)
            del self.video_list[index]
        print(f"更新後的影片列表: {self.video_list}")

    def start_extraction(self):
        if not self.video_list:
            messagebox.showwarning("警告", "請先選擇影片")
            return

        # 驗證輸入的擷取張數
        try:
            frame_count = int(self.frame_count_entry.get())
            if frame_count < 1 or frame_count > 1000:
                raise ValueError
        except ValueError:
            messagebox.showerror("錯誤", "請輸入有效的擷取張數（1-1000）")
            return

        self.frame_count = frame_count
        self.is_running = True
        self.current_index = 0
        self.total_videos = len(self.video_list)

        print(f"開始擷取，擷取張數: {self.frame_count}, 總影片數: {self.total_videos}")

        # 禁用按鈕
        self.start_button.configure(state='disabled')
        self.pause_button.configure(state='normal')
        self.stop_button.configure(state='normal')

        # 在新執行緒中啟動影片處理
        threading.Thread(target=self.process_videos, daemon=True).start()

    def process_videos(self):
        while self.current_index < len(self.video_list) and self.is_running:
            video_path = self.video_list[self.current_index]
            video_name = os.path.basename(video_path)
            sanitized_video_name = self.sanitize_filename(video_name)
            base_output_dir = os.path.join(os.path.dirname(video_path), os.path.splitext(sanitized_video_name)[0])
            unique_output_dir = self.get_unique_output_dir(base_output_dir)
            output_dir = os.path.normpath(unique_output_dir)

            print(f"處理影片: {video_path}")
            print(f"輸出目錄: {output_dir}")

            # 確保輸出目錄存在
            if not os.path.exists(output_dir):
                try:
                    os.makedirs(output_dir)
                    print(f"已創建輸出目錄: {output_dir}")
                except Exception as e:
                    print(f"無法創建輸出目錄: {output_dir}, 錯誤: {e}")
                    self.root.after(0, lambda: messagebox.showerror("錯誤", f"無法創建輸出目錄: {output_dir}"))
                    self.current_index += 1
                    continue

            cap = cv2.VideoCapture(video_path)
            if not cap.isOpened():
                self.root.after(0, lambda: messagebox.showerror("錯誤", f"無法打開影片檔案: {video_name}"))
                print(f"無法打開影片檔案: {video_name}")
                self.current_index += 1
                continue

            fps = cap.get(cv2.CAP_PROP_FPS)
            frame_count_total = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
            duration = frame_count_total / fps if fps else 0
            interval_seconds = duration / self.frame_count if self.frame_count else 0
            interval_frames = max(int(math.floor(interval_seconds * fps)), 1)

            print(f"影片 FPS: {fps}, 總幀數: {frame_count_total}, 持續時間 (秒): {duration}, 間隔幀數: {interval_frames}")

            saved = 0
            count = 0

            # 重置進度條
            self.root.after(0, lambda: self.progress_bar.set(0))
            self.root.after(0, lambda: self.progress_label.configure(text="進度：0%"))

            # 取得影片類別
            video_type = self.video_type_var.get() if self.video_type_var.get() else "1"

            # 插入 video_master 資料
            # video_master_id = None
            # if self.db_cursor:
            #     try:
            #         insert_video = """
            #             INSERT INTO video_master (video_name, video_path, duration, video_type)
            #             VALUES (%s, %s, %s, %s)
            #         """
            #         relative_video_path = f"\\{os.path.basename(output_dir)}\\{video_name}"
            #         self.db_cursor.execute(insert_video, (video_name, relative_video_path, round(duration, 2), video_type))
            #         self.db_connection.commit()
            #         video_master_id = self.db_cursor.lastrowid
            #         print(f"已插入 video_master, ID: {video_master_id}")
            #     except mysql.connector.Error as err:
            #         print(f"插入 video_master 時發生錯誤: {err}")
            #         traceback.print_exc()

            while cap.isOpened() and self.is_running and saved < self.frame_count:
                ret, frame = cap.read()
                if not ret:
                    print("無法讀取更多幀，結束當前影片處理")
                    break
                if count % interval_frames == 0:
                    # 儲存截圖
                    frame_filename = self.sanitize_filename(f"{os.path.splitext(video_name)[0]}_{saved + 1}.jpg")
                    frame_path = os.path.join(output_dir, frame_filename)
                    frame_path = os.path.normpath(frame_path)

                    print(f"儲存截圖至: {frame_path}")

                    if frame is not None:
                        try:
                            # 使用 PIL 來保存圖片以處理 Unicode 路徑問題
                            rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                            pil_image = Image.fromarray(rgb_frame)
                            pil_image.save(frame_path)
                            print(f"成功儲存截圖至: {frame_path}")
                        except Exception as e:
                            print(f"儲存影像時發生錯誤: {e}")
                            traceback.print_exc()
                            continue

                        # 插入 video_screenshots 資料
                        # screenshot_relative_path = os.path.relpath(frame_path, os.path.dirname(video_path))
                        # screenshot_id = None
                        # if self.db_cursor and video_master_id:
                        #     try:
                        #         insert_screenshot = """
                        #             INSERT INTO video_screenshots (video_master_id, screenshot_path)
                        #             VALUES (%s, %s)
                        #         """
                        #         screenshot_db_path = f"\\{os.path.basename(output_dir)}\\{frame_filename}"
                        #         self.db_cursor.execute(insert_screenshot, (video_master_id, screenshot_db_path))
                        #         self.db_connection.commit()
                        #         screenshot_id = self.db_cursor.lastrowid
                        #         print(f"已插入 video_screenshots, ID: {screenshot_id}")
                        #     except mysql.connector.Error as err:
                        #         print(f"插入 video_screenshots 時發生錯誤: {err}")
                        #         traceback.print_exc()

                        saved += 1

                        # 更新當前處理檔案名稱
                        self.root.after(0, lambda filename=frame_filename: self.current_file_label.configure(text=f"目前處理檔案：{filename}"))

                        # 人臉偵測
                        faces = self.detect_faces(frame)
                        if faces:
                            for idx, face in enumerate(faces):
                                face_filename = self.sanitize_filename(f"{os.path.splitext(video_name)[0]}_face_{saved}_{idx + 1}.jpg")
                                face_path = os.path.join(output_dir, face_filename)
                                face_path = os.path.normpath(face_path)

                                print(f"儲存人臉至: {face_path}")

                                if face.size > 0:  # 確保裁切到的影像有效
                                    try:
                                        # 使用 PIL 來保存圖片以處理 Unicode 路徑問題
                                        rgb_face = cv2.cvtColor(face, cv2.COLOR_BGR2RGB)
                                        pil_face = Image.fromarray(rgb_face)
                                        pil_face.save(face_path)
                                        print(f"成功儲存人臉至: {face_path}")
                                    except Exception as e:
                                        print(f"儲存人臉時發生錯誤: {e}")
                                        traceback.print_exc()
                                        continue

                                    # 插入 video_face_screenshots 資料
                                    # if self.db_cursor and screenshot_id:
                                    #     try:
                                    #         insert_face = """
                                    #             INSERT INTO video_face_screenshots (video_screenshot_id, face_image_path)
                                    #             VALUES (%s, %s)
                                    #         """
                                    #         face_db_path = f"\\{os.path.basename(output_dir)}\\{face_filename}"
                                    #         self.db_cursor.execute(insert_face, (screenshot_id, face_db_path))
                                    #         self.db_connection.commit()
                                    #         print(f"已插入 video_face_screenshots")
                                    #     except mysql.connector.Error as err:
                                    #         print(f"插入 video_face_screenshots 時發生錯誤: {err}")
                                    #         traceback.print_exc()

                    # 更新進度條
                    progress = saved / self.frame_count
                    percent = int(progress * 100)
                    self.root.after(0, lambda progress=progress, percent=percent: [
                        self.progress_bar.set(progress),
                        self.progress_label.configure(text=f"進度：{percent}%")
                    ])

                count += 1

            cap.release()
            print(f"完成處理影片: {video_path}")

            # 移動影片檔案到輸出目錄
            try:
                destination_path = os.path.join(output_dir, video_name)
                shutil.move(video_path, destination_path)
                print(f"已移動影片檔案至: {destination_path}")
            except Exception as e:
                print(f"移動影片檔案時發生錯誤: {e}")
                traceback.print_exc()

            # 移除已處理的影片從清單
            self.remove_processed_video(self.current_index)

            self.current_index += 1

        self.processing_complete()

    def remove_processed_video(self, index):
        if index < len(self.video_list):
            print(f"從清單中移除已處理的影片: {self.video_list[index]}")
            del self.video_list[index]
            self.video_listbox.delete(index)

    def processing_complete(self):
        # 不顯示完成的提示框
        print("所有影片已處理完成")
        self.progress_bar.set(0)
        self.progress_label.configure(text="進度：100%")
        self.current_file_label.configure(text="目前處理檔案：無")
        self.is_running = False

        # 重新啟用按鈕
        self.start_button.configure(state='normal')
        self.pause_button.configure(state='disabled')
        self.stop_button.configure(state='disabled')

    def detect_faces(self, frame):
        # 使用 MTCNN 進行人臉偵測，若無法偵測則嘗試旋轉影像
        faces = []
        angles = [45, 90, 135, 180, 225, 270, 315]
        try:
            rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
            results = self.detector.detect_faces(rgb_frame)
            if not results:
                for angle in angles:
                    rotated = self.rotate_image(frame, angle)
                    rgb_rotated = cv2.cvtColor(rotated, cv2.COLOR_BGR2RGB)
                    results = self.detector.detect_faces(rgb_rotated)
                    if results:
                        # 將偵測到的人臉座標轉回原始影像
                        for result in results:
                            x, y, width, height = result['box']
                            face = self.extract_face(rotated, x, y, width, height, angle)
                            if face is not None:
                                faces.append(face)
                        break
            else:
                for result in results:
                    x, y, width, height = result['box']
                    face = self.extract_face(frame, x, y, width, height, 0)
                    if face is not None:
                        faces.append(face)
            print(f"偵測到 {len(faces)} 張人臉")
        except Exception as e:
            print(f"人臉偵測時發生錯誤: {e}")
            traceback.print_exc()
        return faces

    def rotate_image(self, image, angle):
        """旋轉影像並保持整個影像在視窗中"""
        (h, w) = image.shape[:2]
        center = (w // 2, h // 2)
        M = cv2.getRotationMatrix2D(center, angle, 1.0)
        cos = np.abs(M[0, 0])
        sin = np.abs(M[0, 1])
        # 計算新的尺寸
        nW = int((h * sin) + (w * cos))
        nH = int((h * cos) + (w * sin))
        # 調整旋轉矩陣
        M[0, 2] += (nW / 2) - center[0]
        M[1, 2] += (nH / 2) - center[1]
        rotated = cv2.warpAffine(image, M, (nW, nH))
        return rotated

    def extract_face(self, rotated_image, x, y, width, height, angle):
        """從旋轉後的影像中提取並旋轉回原始方向的人臉，並擴大框選區域以包含上半身"""
        try:
            # 設定擴大倍數
            expansion_factor = 2.0  # 可根據需要調整

            # 計算新的寬高
            new_width = int(width * expansion_factor)
            new_height = int(height * expansion_factor)

            # 計算新的 x, y，確保中心點不變
            center_x = x + width // 2
            center_y = y + height // 2
            new_x = int(center_x - new_width // 2)
            new_y = int(center_y - new_height // 2)

            # 確保新的 x, y 在影像範圍內
            new_x = max(new_x, 0)
            new_y = max(new_y, 0)
            new_width = min(new_width, rotated_image.shape[1] - new_x)
            new_height = min(new_height, rotated_image.shape[0] - new_y)

            face = rotated_image[new_y:new_y+new_height, new_x:new_x+new_width]
            if angle != 0:
                face = self.rotate_face_back(face, angle)
            return face
        except Exception as e:
            print(f"提取人臉時發生錯誤: {e}")
            traceback.print_exc()
            return None

    def rotate_face_back(self, face_image, angle):
        """將提取的人臉影像旋轉回正面"""
        try:
            # 計算旋轉中心
            (h, w) = face_image.shape[:2]
            center = (w // 2, h // 2)
            # 計算旋轉矩陣
            M = cv2.getRotationMatrix2D(center, -angle, 1.0)
            # 旋轉影像
            rotated_back = cv2.warpAffine(face_image, M, (w, h), flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
            return rotated_back
        except Exception as e:
            print(f"旋轉人臉回正面時發生錯誤: {e}")
            traceback.print_exc()
            return face_image  # 返回原始影像以防失敗

    def pause_extraction(self):
        if self.is_running:
            self.is_running = False
            self.current_file_label.configure(text="目前處理檔案：暫停中")
            print("擷取已暫停")
            # 禁用暫停按鈕，啟用執行按鈕以便繼續
            self.pause_button.configure(state='disabled')
            self.start_button.configure(state='normal')

    def stop_extraction(self):
        self.is_running = False
        self.progress_bar.set(0)
        self.progress_label.configure(text="進度：0%")
        self.current_file_label.configure(text="目前處理檔案：無")
        self.current_index = len(self.video_list)
        print("擷取已停止")
        # 重新啟用按鈕
        self.start_button.configure(state='normal')
        self.pause_button.configure(state='disabled')
        self.stop_button.configure(state='disabled')

    def __del__(self):
        # """
        # if hasattr(self, 'db_connection') and self.db_connection and self.db_connection.is_connected():
        #     self.db_cursor.close()
        #     self.db_connection.close()
        #     print("資料庫連線已關閉")
        # """
        pass

if __name__ == "__main__":
    app = CTk()
    extractor = FaceExtractorApp(app)
    app.mainloop()
